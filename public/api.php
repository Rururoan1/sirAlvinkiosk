<?php
// The entire backend. Every request is:  api.php?action=<name>
// Requests and responses are JSON. Errors come back as {"error": "..."} with a matching HTTP status.

declare(strict_types=1);

session_name('kiosk_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

header('Content-Type: application/json; charset=utf-8');

// ---------- helpers ----------

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['error' => $message], $status);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = require __DIR__ . '/../config.php';
        $pdo = new PDO(
            "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

// Every query goes through here as a prepared statement.
function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ---------- kiosk ----------

function start(): never
{
    respond([
        'danger_signs' => query('SELECT id, label FROM danger_signs ORDER BY id')->fetchAll(),
        'symptoms'     => query('SELECT id, name FROM symptoms ORDER BY id')->fetchAll(),
    ]);
}

function questions(): never
{
    $symptomId = (int) ($_GET['symptom_id'] ?? 0);

    $questions = query(
        'SELECT id, question_key, question_text FROM followup_questions
         WHERE symptom_id = ? ORDER BY sort_order, id',
        [$symptomId]
    )->fetchAll();

    if (!$questions) {
        fail('Symptom not found.', 404);
    }

    foreach ($questions as $i => $q) {
        $questions[$i]['options'] = query(
            'SELECT option_text, option_value FROM followup_options WHERE question_id = ? ORDER BY id',
            [$q['id']]
        )->fetchAll();
    }

    respond(['questions' => $questions]);
}

function record(string $deviceId, ?int $symptomId, ?int $medicineId, string $resultType): void
{
    query(
        'INSERT INTO interaction_logs (device_id, symptom_id, medicine_id, result_type) VALUES (?, ?, ?, ?)',
        [$deviceId, $symptomId, $medicineId, $resultType]
    );
}

// The decision rules, in order. The first one that applies wins:
//   1. A danger sign is checked          -> refer to the clinic
//   2. A follow-up answer is "severe"    -> refer to the clinic
//   3. Daily limit for this device hit   -> refer to the clinic
//   4. Otherwise                         -> recommend the medicine
function submit(array $body): never
{
    $deviceId = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string) ($body['device_id'] ?? '')), 0, 64);
    if ($deviceId === '') {
        fail('Missing device id.', 422);
    }

    $dangerFlags = is_array($body['danger_flags'] ?? null) ? $body['danger_flags'] : [];
    $answers     = is_array($body['answers'] ?? null) ? $body['answers'] : [];
    $symptomId   = isset($body['symptom_id']) ? (int) $body['symptom_id'] : null;

    // Rule 1
    if ($dangerFlags) {
        record($deviceId, $symptomId, null, 'referral_danger');
        respond(['result' => [
            'type' => 'referral',
            'message' => 'You may have a serious symptom. Go to the school clinic now for medical attention.',
        ]]);
    }

    if ($symptomId === null) {
        fail('Please choose a symptom.', 422);
    }

    // Rule 2
    foreach ($answers as $key => $value) {
        $severe = query(
            'SELECT 1 FROM followup_options fo
             JOIN followup_questions fq ON fq.id = fo.question_id
             WHERE fq.symptom_id = ? AND fq.question_key = ? AND fo.option_value = ? AND fo.is_severe = 1
             LIMIT 1',
            [$symptomId, (string) $key, (string) $value]
        )->fetch();

        if ($severe) {
            record($deviceId, $symptomId, null, 'referral_severe');
            respond(['result' => [
                'type' => 'referral',
                'message' => 'Your symptoms may be more serious than a common, low-risk case. Go to the school clinic for medical attention.',
            ]]);
        }
    }

    $medicine = query('SELECT * FROM medicines WHERE symptom_id = ?', [$symptomId])->fetch();
    if (!$medicine) {
        fail('No recommendation is set up for this symptom.', 404);
    }

    // Rule 3
    $usedToday = (int) query(
        "SELECT COUNT(*) FROM interaction_logs
         WHERE device_id = ? AND medicine_id = ? AND result_type = 'medicine'
           AND created_at > NOW() - INTERVAL 1 DAY",
        [$deviceId, $medicine['id']]
    )->fetchColumn();

    if ($usedToday >= (int) $medicine['max_per_24h']) {
        record($deviceId, $symptomId, (int) $medicine['id'], 'limit_reached');
        respond(['result' => [
            'type' => 'referral',
            'message' => 'You have reached today\'s limit for this medicine. Go to the school clinic for further help.',
        ]]);
    }

    // Rule 4
    record($deviceId, $symptomId, (int) $medicine['id'], 'medicine');
    respond(['result' => [
        'type' => 'medicine',
        'medicine' => [
            'name'    => $medicine['name'],
            'purpose' => $medicine['purpose'],
            'dosage'  => $medicine['dosage'],
            'timing'  => $medicine['timing'],
        ],
        'remaining_today' => max(0, (int) $medicine['max_per_24h'] - $usedToday - 1),
    ]]);
}

// ---------- staff dashboard ----------

function login(array $body): never
{
    $username = substr((string) ($body['username'] ?? ''), 0, 50);
    $password = (string) ($body['password'] ?? '');
    if ($username === '' || $password === '') {
        fail('Enter your username and password.', 422);
    }

    // Lock out after 5 failed tries in 15 minutes (per IP + username).
    $key = ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $username;
    query('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    $failures = (int) query(
        'SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND created_at > NOW() - INTERVAL 15 MINUTE',
        [$key]
    )->fetchColumn();
    if ($failures >= 5) {
        fail('Too many failed attempts. Try again in 15 minutes.', 429);
    }

    $user = query('SELECT username, password_hash FROM admin_users WHERE username = ?', [$username])->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        query('INSERT INTO login_attempts (attempt_key) VALUES (?)', [$key]);
        fail('Wrong username or password.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = $user['username'];
    respond(['username' => $user['username']]);
}

function logout(): never
{
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true]);
}

function stats(): never
{
    if (empty($_SESSION['admin'])) {
        fail('Not logged in.', 401);
    }

    $totals = ['medicine' => 0, 'referral_danger' => 0, 'referral_severe' => 0, 'limit_reached' => 0];
    foreach (query('SELECT result_type, COUNT(*) FROM interaction_logs GROUP BY result_type')->fetchAll(PDO::FETCH_KEY_PAIR) as $type => $count) {
        $totals[$type] = (int) $count;
    }

    $bySymptom = query(
        'SELECT s.name, COUNT(*) AS total
         FROM interaction_logs l JOIN symptoms s ON s.id = l.symptom_id
         GROUP BY s.id, s.name ORDER BY total DESC LIMIT 10'
    )->fetchAll();

    $last7Days = query(
        'SELECT DATE(created_at) AS day, COUNT(*) AS total
         FROM interaction_logs WHERE created_at > NOW() - INTERVAL 7 DAY
         GROUP BY DATE(created_at) ORDER BY day'
    )->fetchAll();

    // Recent activity, 10 per page. $perPage and $offset are integers, so they are safe to put in the SQL.
    $perPage = 10;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;
    $recent  = query(
        "SELECT l.device_id, l.result_type, l.created_at, s.name AS symptom, m.name AS medicine
         FROM interaction_logs l
         LEFT JOIN symptoms s ON s.id = l.symptom_id
         LEFT JOIN medicines m ON m.id = l.medicine_id
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT $perPage OFFSET $offset"
    )->fetchAll();
    foreach ($recent as $i => $row) {
        $recent[$i]['device_id'] = substr($row['device_id'], 0, 8); // never show the full token
    }

    $count = (int) query('SELECT COUNT(*) FROM interaction_logs')->fetchColumn();

    respond([
        'username'    => $_SESSION['admin'],
        'totals'      => $totals,
        'total'       => array_sum($totals),
        'by_symptom'  => $bySymptom,
        'last_7_days' => $last7Days,
        'recent'      => $recent,
        'page'        => $page,
        'total_pages' => max(1, (int) ceil($count / $perPage)),
    ]);
}

// ---------- chatbot (Ollama) ----------

function config(): array
{
    static $c = null;
    return $c ??= require __DIR__ . '/../config.php';
}

// Words that mean "emergency". These are checked in PHP BEFORE the AI is called,
// so the answer is always the fixed clinic message, whatever the AI would have said.
// (A student writing "I don't have chest pain" will also trigger this. That is on purpose: it errs on the safe side.)
function chat_emergency_type(string $text): ?string
{
    $selfHarm = '/\b(kill myself|suicid\w*|want to die|end my life|hurt myself|harm myself|self[- ]?harm|magpakamatay)\b/i';
    $medical  = '/(can\'?t breathe|cannot breathe|can\'?t catch my breath|trouble breathing|difficulty breathing|hard to breathe|short(ness)? of breath|hirap (ako )?huminga'
              . '|chest pain|chest pressure|tight chest|pain in (my )?chest|masakit (ang )?dibdib'
              . '|faint(ed|ing)?|passed out|pass out|unconscious|blacked out|nahimatay'
              . '|seizure|convulsion'
              . '|bleeding (a lot|heavily|badly)|won\'?t stop bleeding|severe bleeding|cough(ing)? (up )?blood|vomit(ing)? blood'
              . '|swollen (face|lips|tongue|throat)|throat (is )?(closing|swelling)|allergic reaction|anaphyla\w*|hives'
              . '|slurred speech|face (is )?droop\w*|can\'?t move (my )?(arm|leg)'
              . '|overdos\w*|took too many (pills|tablets)|poison\w*'
              . '|worst headache)/i';

    if (preg_match($selfHarm, $text)) {
        return 'selfharm';
    }
    return preg_match($medical, $text) ? 'emergency' : null;
}

function chat_fixed_message(string $type): string
{
    $c = config();
    $clinic = "{$c['clinic_name']} ({$c['clinic_location']}; {$c['clinic_hours']})";

    if ($type === 'selfharm') {
        return "I'm really sorry you're feeling this way, and I'm glad you told me. You are not alone. "
             . "Please talk to a teacher, a staff member, or the guidance counselor right now, or go to the {$c['clinic_name']}. "
             . "If you might hurt yourself or you are in danger, call {$c['emergency_number']} immediately.";
    }
    return "This could be serious. Please go to the {$clinic} RIGHT NOW, or ask a teacher or staff member nearby to take you. "
         . "If it is an emergency, call {$c['emergency_number']} immediately. Do not wait.";
}

function chat_system_prompt(): string
{
    $c = config();

    // The AI is only allowed to mention the same medicines the kiosk recommends.
    $lines = [];
    foreach (query('SELECT s.name AS symptom, m.name, m.dosage, m.timing FROM medicines m JOIN symptoms s ON s.id = m.symptom_id ORDER BY s.id')->fetchAll() as $m) {
        $lines[] = "- For {$m['symptom']}: {$m['name']}. Dosage: {$m['dosage']}. Timing: {$m['timing']}.";
    }

    $template = (string) file_get_contents(__DIR__ . '/../prompts/chatbot_system_prompt.txt');
    return strtr($template, [
        '{{CLINIC_NAME}}'       => $c['clinic_name'],
        '{{CLINIC_LOCATION}}'   => $c['clinic_location'],
        '{{CLINIC_HOURS}}'      => $c['clinic_hours'],
        '{{EMERGENCY_NUMBER}}'  => $c['emergency_number'],
        '{{MEDICINES}}'         => implode("\n", $lines),
    ]);
}

function chat(array $body): never
{
    set_time_limit(150); // local AI can be slow; PHP's default is 30 seconds

    // Clean the history sent by the browser: only user/assistant roles, short text, last 10 messages.
    $history = [];
    foreach (is_array($body['messages'] ?? null) ? $body['messages'] : [] as $m) {
        $role = $m['role'] ?? '';
        $text = trim(mb_substr((string) ($m['content'] ?? ''), 0, 500));
        if (($role === 'user' || $role === 'assistant') && $text !== '') {
            $history[] = ['role' => $role, 'content' => $text];
        }
    }
    $history = array_slice($history, -10);

    $last = end($history);
    if (!$last || $last['role'] !== 'user') {
        fail('Please type a message.', 422);
    }

    // Safety net: emergency words never reach the AI.
    $emergency = chat_emergency_type($last['content']);
    if ($emergency !== null) {
        respond(['type' => 'emergency', 'reply' => chat_fixed_message($emergency)]);
    }

    $c = config();
    $payload = json_encode([
        'model'      => $c['ollama_model'],
        'stream'     => false,
        'keep_alive' => '30m',
        'options'    => ['temperature' => 0.3, 'num_predict' => 300],
        'messages'   => array_merge([['role' => 'system', 'content' => chat_system_prompt()]], $history),
    ]);

    $raw = @file_get_contents(rtrim($c['ollama_url'], '/') . '/api/chat', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nUser-Agent: kiosk-php\r\n",
            'content' => $payload,
            'timeout' => (int) $c['ollama_timeout'],
            'ignore_errors' => true,
        ],
    ]));

    $reply = '';
    if ($raw !== false) {
        $data  = json_decode($raw, true);
        $reply = trim((string) ($data['message']['content'] ?? ''));
        if ($reply === '' && isset($data['error'])) {
            error_log('Ollama error: ' . $data['error']);
        }
    } else {
        error_log('Ollama not reachable at ' . $c['ollama_url']);
    }

    if ($reply === '') {
        respond(['type' => 'error', 'reply' =>
            "Sorry, the chatbot is not available right now. You can use the kiosk buttons instead, "
            . "or go to the {$c['clinic_name']} ({$c['clinic_location']}) for help."]);
    }

    respond(['type' => 'chat', 'reply' => $reply]);
}

// ---------- routing ----------

$body = json_decode(file_get_contents('php://input') ?: '', true);
$body = is_array($body) ? $body : [];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

try {
    switch ($_GET['action'] ?? '') {
        case 'start':     start();
        case 'questions': questions();
        case 'stats':     stats();
        case 'submit':    $isPost ? submit($body) : fail('Use POST.', 405);
        case 'chat':      $isPost ? chat($body) : fail('Use POST.', 405);
        case 'login':     $isPost ? login($body) : fail('Use POST.', 405);
        case 'logout':    $isPost ? logout() : fail('Use POST.', 405);
        default:          fail('Not found.', 404);
    }
} catch (Throwable $e) {
    // Details go to the server's error log, never to the browser.
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    fail('Something went wrong. Please try again.', 500);
}

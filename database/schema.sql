-- Student Health Kiosk database.
-- Import this file once (phpMyAdmin > Import, or: mysql -u root < database/schema.sql)

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS kioskfinal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kioskfinal;

-- Red-flag checklist shown first. Any checked item sends the student to the clinic.
CREATE TABLE danger_signs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(150) NOT NULL
);

CREATE TABLE symptoms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL
);

CREATE TABLE followup_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  symptom_id INT NOT NULL,
  question_key VARCHAR(50) NOT NULL,
  question_text VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (symptom_id) REFERENCES symptoms(id) ON DELETE CASCADE
);

-- is_severe = 1 means picking this option sends the student to the clinic.
CREATE TABLE followup_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  option_text VARCHAR(150) NOT NULL,
  option_value VARCHAR(50) NOT NULL,
  is_severe TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (question_id) REFERENCES followup_questions(id) ON DELETE CASCADE
);

-- One recommended medicine per symptom.
CREATE TABLE medicines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  symptom_id INT NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  dosage VARCHAR(150) NOT NULL,
  timing VARCHAR(150) NOT NULL,
  max_per_24h INT NOT NULL DEFAULT 3,
  FOREIGN KEY (symptom_id) REFERENCES symptoms(id) ON DELETE CASCADE
);

-- Every kiosk result, anonymous. device_id is a random per-browser token, not a student identity.
CREATE TABLE interaction_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  symptom_id INT DEFAULT NULL,
  medicine_id INT DEFAULT NULL,
  result_type ENUM('medicine','referral_danger','referral_severe','limit_reached') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (symptom_id) REFERENCES symptoms(id) ON DELETE SET NULL,
  FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE SET NULL,
  INDEX idx_device_medicine (device_id, medicine_id, created_at),
  INDEX idx_created (created_at)
);

CREATE TABLE admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL
);

-- Failed staff logins, used to lock out password guessing.
CREATE TABLE login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_key VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt (attempt_key, created_at)
);

-- ---------------------------------------------------------
-- Starting data
-- ---------------------------------------------------------

INSERT INTO danger_signs (label) VALUES
('Difficulty breathing / shortness of breath'),
('Chest pain or pressure'),
('Very high fever (39°C / 102°F or above)'),
('Signs of allergic reaction (swelling, rash, hives)'),
('Severe or uncontrolled bleeding'),
('Fainting or loss of consciousness');

INSERT INTO symptoms (name) VALUES
('Headache'), ('Cough'), ('Colds / Flu'), ('Fever'), ('Body / Muscle Pain'), ('Sore Throat');

INSERT INTO followup_questions (symptom_id, question_key, question_text, sort_order) VALUES
(1, 'severity', 'How would you rate your headache?', 1),
(1, 'duration', 'How long have you had this headache?', 2),
(2, 'severity', 'What type of cough do you have?', 1),
(2, 'duration', 'How long have you had this cough?', 2),
(3, 'severity', 'How would you describe your cold symptoms?', 1),
(3, 'duration', 'How long have your symptoms lasted?', 2),
(4, 'severity', 'What is your approximate temperature?', 1),
(4, 'duration', 'How long have you had the fever?', 2),
(5, 'severity', 'How intense is the body/muscle pain?', 1),
(5, 'duration', 'How long have you had this pain?', 2),
(6, 'severity', 'How would you rate your sore throat?', 1),
(6, 'duration', 'How long have you had a sore throat?', 2);

INSERT INTO followup_options (question_id, option_text, option_value, is_severe) VALUES
(1, 'Mild', 'mild', 0),
(1, 'Moderate', 'moderate', 0),
(1, 'Severe / worst headache of my life', 'severe', 1),
(2, 'Less than 1 day', 'short', 0),
(2, '1-3 days', 'medium', 0),
(2, 'More than 3 days', 'long', 1),
(3, 'Mild dry cough', 'mild', 0),
(3, 'Productive cough (with phlegm)', 'moderate', 0),
(3, 'Coughing up blood', 'severe', 1),
(4, 'Less than 3 days', 'short', 0),
(4, '3-7 days', 'medium', 0),
(4, 'More than 1 week', 'long', 1),
(5, 'Mild (runny nose, sneezing)', 'mild', 0),
(5, 'Moderate (congestion, body ache)', 'moderate', 0),
(5, 'Severe (can barely function)', 'severe', 1),
(6, 'Less than 3 days', 'short', 0),
(6, '3-7 days', 'medium', 0),
(6, 'More than 1 week', 'long', 1),
(7, 'Below 38°C (100.4°F)', 'mild', 0),
(7, '38-38.9°C (100.4-102°F)', 'moderate', 0),
(7, '39°C (102°F) or higher', 'severe', 1),
(8, 'Less than 1 day', 'short', 0),
(8, '1-2 days', 'medium', 0),
(8, 'More than 2 days', 'long', 1),
(9, 'Mild soreness', 'mild', 0),
(9, 'Moderate ache', 'moderate', 0),
(9, 'Severe pain affecting movement', 'severe', 1),
(10, 'Less than 2 days', 'short', 0),
(10, '2-5 days', 'medium', 0),
(10, 'More than 5 days', 'long', 1),
(11, 'Mild irritation', 'mild', 0),
(11, 'Moderate pain when swallowing', 'moderate', 0),
(11, 'Severe pain, can barely swallow', 'severe', 1),
(12, 'Less than 3 days', 'short', 0),
(12, '3-7 days', 'medium', 0),
(12, 'More than 1 week', 'long', 1);

INSERT INTO medicines (symptom_id, name, purpose, dosage, timing, max_per_24h) VALUES
(1, 'Paracetamol (Biogesic) 500mg', 'Relieves mild to moderate headache pain', '1 tablet every 4-6 hours', 'After meals, max 3 times a day', 3),
(2, 'Ascof Forte / Bioflu Cough Syrup', 'Relieves cough and soothes throat irritation', '10ml (2 tsp) every 6-8 hours', 'After meals', 3),
(3, 'Neozep / Decolgen Forte', 'Relieves colds, runny nose, and congestion', '1 tablet every 6 hours', 'After meals, avoid before driving (may cause drowsiness)', 3),
(4, 'Paracetamol (Biogesic) 500mg', 'Reduces fever and body discomfort', '1 tablet every 4-6 hours', 'After meals, stay hydrated', 3),
(5, 'Mefenamic Acid / Ibuprofen', 'Relieves muscle and body pain, reduces inflammation', '1 tablet every 6-8 hours', 'After meals to avoid stomach upset', 3),
(6, 'Lozenges + Paracetamol 500mg', 'Soothes throat irritation and reduces pain', '1 lozenge as needed; 1 Paracetamol tablet every 6 hours', 'Lozenges as needed, tablet after meals', 3);

-- Default staff login: admin / kiosk2026  (change it, see README)
INSERT INTO admin_users (username, password_hash) VALUES
('admin', '$2y$10$OFU.m20xIEc220.St3G5TuBw2PW0gjuK1iG/16gPXHRYLJSGKmGam');

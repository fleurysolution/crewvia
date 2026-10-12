-- P5-M07: texting and calling through the staff member's own phone (Google
-- Voice or the phone's own apps), from wording kept once and filled from
-- what Crewvia already holds.
--
-- No SMS or calling provider is connected: Crewvia sends nothing itself. A
-- text opens on the phone, already written, and is logged in the existing
-- contact register (candidate_calls) the moment it is opened. Someone who
-- asked not to be texted, or called, is never offered it again.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m07.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Wording kept once. Placeholders in braces are filled from the person,
-- the assignment, the hotel and the travel already recorded.
CREATE TABLE IF NOT EXISTS message_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  language CHAR(2) NOT NULL DEFAULT 'en',
  purpose ENUM('candidate','placement') NOT NULL DEFAULT 'candidate',
  body VARCHAR(1000) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_template_name (name, language),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A person who asked not to be texted, or not to be called, and since when.
CREATE TABLE IF NOT EXISTS contact_preferences (
  candidate_id INT UNSIGNED NOT NULL PRIMARY KEY,
  no_texts_since DATETIME NULL,
  no_calls_since DATETIME NULL,
  reason VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO message_templates (name, language, purpose, body) VALUES
  ('Following up', 'en', 'candidate', 'Hi {first_name}, this is {sender} with {agency}. Thanks for applying. Is now a good time to talk about the work?'),
  ('Following up', 'es', 'candidate', 'Hola {first_name}, le escribe {sender} de {agency}. Gracias por su solicitud. ¿Es un buen momento para hablar del trabajo?'),
  ('Start reminder', 'en', 'placement', 'Hi {first_name}, a reminder from {agency}: you start on {project} on {start_date}. Reply here with any question.'),
  ('Start reminder', 'es', 'placement', 'Hola {first_name}, un recordatorio de {agency}: comienza en {project} el {start_date}. Responda aquí si tiene preguntas.'),
  ('Travel and hotel', 'en', 'placement', 'Hi {first_name}, your travel for {project}: {travel}. Hotel: {hotel}, {hotel_address}, check-in {check_in}. Pickup: {pickup}.'),
  ('Travel and hotel', 'es', 'placement', 'Hola {first_name}, su viaje para {project}: {travel}. Hotel: {hotel}, {hotel_address}, entrada {check_in}. Recogida: {pickup}.');

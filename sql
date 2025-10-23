/* =========================================================
   BAZA ZA DOGOVORENO.COM — NADOGRADNJA TVOG STARTA
   (kompatibilno s MySQL 8.0+, utf8mb4)
   ========================================================= */

-- Siguran charset/collation
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================
-- 1) OSNOVNE TABLICE (TVOJE)
-- =========================

CREATE TABLE IF NOT EXISTS users(
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(60) NOT NULL,
  verified TINYINT(1) DEFAULT 0,
  verify_token VARCHAR(64),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS providers(
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  skills TEXT,                               -- privremeno; prelazimo na provider_skills
  location VARCHAR(255),
  bio TEXT,
  reviews_enabled TINYINT(1) DEFAULT 1,
  quiet_from VARCHAR(5) DEFAULT '18:00',
  quiet_to   VARCHAR(5) DEFAULT '07:00',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_users_name       ON users (name);
CREATE INDEX IF NOT EXISTS idx_users_phone      ON users (phone);
CREATE INDEX IF NOT EXISTS idx_providers_latlng ON providers (lat, lng);

-- ==========================================
-- 2) GEO NADOGRADNJA ZA PROVIDERS (SPATIAL)
-- ==========================================
-- POINT kolona (WGS84 / SRID 4326) + SPATIAL INDEX, za brze geo-upite
ALTER TABLE providers
  ADD COLUMN IF NOT EXISTS geo POINT /*!80003 SRID 4326 */ GENERATED ALWAYS AS (
    IF(lat IS NULL OR lng IS NULL, NULL, ST_SRID(POINT(lng, lat), 4326))
  ) STORED,
  ADD SPATIAL INDEX IF NOT EXISTS sidx_providers_geo (geo);

-- ==========================================
-- 3) KATALOG VJEŠTINA + N:M povezivanje
-- ==========================================

CREATE TABLE IF NOT EXISTS skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) GENERATED ALWAYS AS (
    REPLACE(LOWER(
      TRIM(
        REPLACE(REPLACE(REPLACE(REPLACE(name,'č','c'),'ć','c'),'đ','d'),'š','s')
      )
    ), ' ', '-')
  ) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS provider_skills (
  provider_id INT NOT NULL,
  skill_id    INT NOT NULL,
  PRIMARY KEY (provider_id, skill_id),
  FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id)    REFERENCES skills(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_provider_skills_skill ON provider_skills (skill_id);

-- (opcija) inicijalno punjenje skills iz front JSON-a možeš odraditi iz aplikacije;
-- ako želiš, kasnije ću ti dati INSERT skriptu za sve iz /data/skills.json


-- ==========================================
-- 4) POSLOVI (ZAHTJEVI KLIJENATA)
-- ==========================================

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,                             -- ako je korisnik logiran; inače NULL (guest)
  description TEXT NOT NULL,
  skills_json JSON NOT NULL,                    -- npr. ["Keramičar","Vodoinstalater"]
  location_label VARCHAR(255),                  -- npr. maps URL ili "BiH / Mostar — Centar"
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  geo POINT /*!80003 SRID 4326 */ GENERATED ALWAYS AS (ST_SRID(POINT(lng, lat), 4326)) STORED,
  status ENUM('open','matched','closed') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs (status);
CREATE SPATIAL INDEX IF NOT EXISTS sidx_jobs_geo ON jobs (geo);

-- Slike povezane s poslom (svaka kao jedan red)
CREATE TABLE IF NOT EXISTS job_images (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  url VARCHAR(600) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- 5) OBAVIJESTI MAJSTORIMA (MATCH LOG)
-- ==========================================

CREATE TABLE IF NOT EXISTS job_notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  provider_id INT NOT NULL,
  distance_km DECIMAL(7,2) NULL,                 -- izračunato pri slanju
  status ENUM('sent','interested','not_interested') DEFAULT 'sent',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (job_id)     REFERENCES jobs(id)      ON DELETE CASCADE,
  FOREIGN KEY (provider_id)REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_jn_job            ON job_notifications (job_id);
CREATE INDEX IF NOT EXISTS idx_jn_provider       ON job_notifications (provider_id);
CREATE INDEX IF NOT EXISTS idx_jn_status_created ON job_notifications (status, created_at);

-- (opcija) jedinstveno slanje po job+provider, da ne spamamo istoga više puta:
CREATE UNIQUE INDEX IF NOT EXISTS uq_jn_job_provider ON job_notifications (job_id, provider_id);


-- ==========================================
-- 6) POMOĆNE POGLEDE / PRIMJER UPITA
-- ==========================================

/* Primjer: kandidati za job prema zajedničkim skillovima i udaljenosti
   - PARSIRANJE skills_json → join na tablicu skills
   - koristimo ST_Distance_Sphere (MySQL 8.0.12+) za km
   - ograniči na, recimo, 30 najbližih
   (Ovo je primjer SELECT-a; ne kreira view jer uključuje parametre)
   
   SET @jobId = 123;
   SELECT
     p.id AS provider_id,
     u.name,
     u.phone,
     ROUND(ST_Distance_Sphere(p.geo, j.geo)/1000, 2) AS distance_km,
     GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS matched_skills
   FROM jobs j
   JOIN JSON_TABLE(j.skills_json, '$[*]' COLUMNS(skill VARCHAR(120) PATH '$')) js  -- skills iz posla
   JOIN skills s ON s.name = js.skill
   JOIN provider_skills ps ON ps.skill_id = s.id
   JOIN providers p ON p.id = ps.provider_id
   JOIN users u ON u.id = p.user_id
   WHERE j.id = @jobId
     AND p.geo IS NOT NULL
   GROUP BY p.id
   ORDER BY distance_km ASC
   LIMIT 30;
*/


-- ==========================================
-- 7) KORISNE OGRANIČENE POGLEDE (OPCIJA)
-- ==========================================

-- (opcija) View koji nudi “javne” podatke providera (bez precizne lokacije)
DROP VIEW IF EXISTS v_public_providers;
CREATE VIEW v_public_providers AS
SELECT
  p.id,
  u.name,
  u.phone,
  p.location,
  p.bio,
  p.reviews_enabled,
  p.updated_at
FROM providers p
JOIN users u ON u.id = p.user_id;

/* =========================================================
   DOGOVORENO.COM — MATCHES PATCH (MySQL 8.0+)
   ---------------------------------------------------------
   Dodaje:
     1) job_matches — stanje razgovora po jobu i provideru
     2) kolone na jobs za konačni izbor / status matcha
   ========================================================= */

-- 1) KONAČNI IZBOR NA POSLU (opcionalno ali praktično)
ALTER TABLE jobs
  ADD COLUMN IF NOT EXISTS hired_provider_id INT NULL,
  ADD COLUMN IF NOT EXISTS matched_at TIMESTAMP NULL DEFAULT NULL,
  ADD CONSTRAINT fk_jobs_hired_provider
    FOREIGN KEY (hired_provider_id) REFERENCES providers(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_jobs_matched_at ON jobs (matched_at);

-- 2) JOB_MATCHES — dnevnik matcha između posla i majstora
CREATE TABLE IF NOT EXISTS job_matches (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  provider_id INT NOT NULL,
  -- Ako kreirano iz notifikacije, vežemo (nije obavezno)
  notification_id BIGINT NULL,

  /* stanje toka:
     interested   — provider kliknuo "zainteresiran"
     contacted    — klijent kontaktirao providera (ili provider kontaktiran od sustava)
     hired        — klijent odabrao ovog providera (jobs.hired_provider_id = provider_id)
     declined     — klijent odbio ovog providera
     no_response  — provider nije reagirao u roku
     completed    — posao zaključen
     cancelled    — klijent otkazao posao
  */
  status ENUM(
    'interested','contacted','hired','declined','no_response','completed','cancelled'
  ) NOT NULL DEFAULT 'interested',

  -- korisno za listanje / sort
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  -- snapshot korisnih podataka u trenutku matcha (nije obavezno, ali pomaže u analitici)
  distance_km DECIMAL(7,2) NULL,
  price_offer DECIMAL(10,2) NULL,     -- ako ikad uvedeš ponude/cijene
  currency CHAR(3) NULL,              -- npr. 'BAM', 'EUR'
  message_excerpt VARCHAR(280) NULL,  -- kratki sažetak poruke/zahtjeva

  -- integritet veza
  CONSTRAINT fk_jm_job      FOREIGN KEY (job_id)      REFERENCES jobs(id)       ON DELETE CASCADE,
  CONSTRAINT fk_jm_provider FOREIGN KEY (provider_id) REFERENCES providers(id)  ON DELETE CASCADE,
  CONSTRAINT fk_jm_notif    FOREIGN KEY (notification_id) REFERENCES job_notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jedan red po par (job, provider) – bez duplikata:
CREATE UNIQUE INDEX IF NOT EXISTS uq_jm_job_provider ON job_matches (job_id, provider_id);

-- Pretraživanje po statusu i svježini:
CREATE INDEX IF NOT EXISTS idx_jm_status_updated ON job_matches (status, updated_at);
CREATE INDEX IF NOT EXISTS idx_jm_job ON job_matches (job_id);
CREATE INDEX IF NOT EXISTS idx_jm_provider ON job_matches (provider_id);

-- 3) (OPCIJA) POGLED: zadnje stanje po (job, provider)
DROP VIEW IF EXISTS v_job_match_latest;
CREATE VIEW v_job_match_latest AS
SELECT
  jm.*
FROM job_matches jm
JOIN (
  SELECT job_id, provider_id, MAX(updated_at) AS max_upd
  FROM job_matches
  GROUP BY job_id, provider_id
) last ON last.job_id = jm.job_id AND last.provider_id = jm.provider_id AND (jm.updated_at = last.max_upd OR (jm.updated_at IS NULL AND jm.created_at = (
    SELECT MAX(jm2.created_at) FROM job_matches jm2 WHERE jm2.job_id = jm.job_id AND jm2.provider_id = jm.provider_id
)))
;

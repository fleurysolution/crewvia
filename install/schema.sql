-- RSS Ops - schema
--
-- The whole business is one sentence: RSS recruits an engineer, flies them in,
-- beds them, buses them to the plant, counts their hours, pays them and bills
-- the client. So the centre of this schema is not a project and not a job
-- order. It is a PLACEMENT: one person, on one job, for one stretch of time.
-- Every other table hangs off that row.
--
-- Written for MySQL 5.7 / MariaDB 10.x, which is what cPanel gives us.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ─────────────────────────────────────────────────────────────── people who
-- work AT RSS. Seven of them, in four jobs.
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    phone           VARCHAR(40)  NULL,
    password_hash   VARCHAR(255) NOT NULL,
    -- admin sees and does everything; the other three are the jobs the team
    -- told us they do. A role is not a hierarchy here, it is a desk.
    role            ENUM('admin','recruiter','hotels','payroll') NOT NULL DEFAULT 'recruiter',
    job_title       VARCHAR(120) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    must_change_pw  TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────── the client
-- and the job. RSS has more than one client, so this is not hardcoded.
CREATE TABLE IF NOT EXISTS clients (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(190) NOT NULL,
    contact_name  VARCHAR(120) NULL,
    contact_email VARCHAR(190) NULL,
    contact_phone VARCHAR(40)  NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(190) NOT NULL,
    site_name       VARCHAR(190) NULL,
    site_city       VARCHAR(120) NULL,
    site_state      VARCHAR(40)  NULL,

    -- Paco's terms, as data rather than as folklore in somebody's inbox.
    pay_rate        DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    bill_rate       DECIMAL(10,2) NULL,
    guarantee_hours TINYINT UNSIGNED NOT NULL DEFAULT 50,
    strike_hours    TINYINT UNSIGNED NOT NULL DEFAULT 60,
    -- The hinge the whole job turns on. Off: 50-hour guarantee. On: 60.
    strike_live     TINYINT(1) NOT NULL DEFAULT 0,
    per_diem_rate   DECIMAL(8,2) NOT NULL DEFAULT 30.00,

    headcount_target SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    starts_on       DATE NULL,
    ends_on         DATE NULL,
    status          ENUM('planning','active','closed') NOT NULL DEFAULT 'planning',
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (client_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────── the people RSS
-- recruits. A candidate exists before any job is decided for them.
CREATE TABLE IF NOT EXISTS candidates (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(190) NOT NULL,
    email         VARCHAR(190) NULL,
    phone         VARCHAR(40)  NULL,
    city          VARCHAR(120) NULL,
    state         VARCHAR(40)  NULL,

    -- Paco: "You must have a degree in Mechanical or Chemical." So the
    -- discipline is a field, not a note, and it can be filtered on.
    discipline    ENUM('mechanical','chemical','other') NOT NULL DEFAULT 'other',
    degree        VARCHAR(190) NULL,
    years_exp     TINYINT UNSIGNED NULL,

    -- blue nobody has called, yellow in progress, red no, green hired.
    -- Same colours the desk already uses out loud.
    stage         ENUM('new','contacted','screening','offered','accepted','declined','rejected','placed')
                  NOT NULL DEFAULT 'new',
    source        VARCHAR(60) NULL,
    resume_text   MEDIUMTEXT NULL,
    notes         TEXT NULL,

    owner_id      INT UNSIGNED NULL,          -- which recruiter has it
    last_contact_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX (stage), INDEX (discipline), INDEX (owner_id),
    INDEX (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS candidate_calls (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    outcome      ENUM('reached','voicemail','no_answer','callback','not_interested','wrong_number')
                 NOT NULL DEFAULT 'reached',
    note         TEXT NULL,
    called_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (candidate_id, called_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────── the centre
-- One person, on one job, for one stretch. Everything else points here.
CREATE TABLE IF NOT EXISTS placements (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidate_id   INT UNSIGNED NOT NULL,
    job_id         INT UNSIGNED NOT NULL,

    status         ENUM('offered','confirmed','travelling','on_site','completed','cancelled')
                   NOT NULL DEFAULT 'offered',
    start_date     DATE NULL,
    end_date       DATE NULL,

    -- Per-placement overrides. Most people are on the job's terms, but the
    -- day somebody is signed at a different rate this must not require a
    -- second system to record it.
    pay_rate       DECIMAL(10,2) NULL,
    bill_rate      DECIMAL(10,2) NULL,
    per_diem_rate  DECIMAL(8,2)  NULL,

    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_candidate_job (candidate_id, job_id),
    INDEX (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────── hotels
-- Sharesa's desk.
CREATE TABLE IF NOT EXISTS hotels (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(190) NOT NULL,
    address     VARCHAR(255) NULL,
    city        VARCHAR(120) NULL,
    state       VARCHAR(40)  NULL,
    phone       VARCHAR(40)  NULL,
    nightly_rate DECIMAL(10,2) NULL,
    confirmation_contact VARCHAR(190) NULL,
    notes       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lodging (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    placement_id  INT UNSIGNED NOT NULL,
    hotel_id      INT UNSIGNED NOT NULL,
    room_number   VARCHAR(30) NULL,
    confirmation  VARCHAR(60) NULL,
    check_in      DATE NULL,
    check_out     DATE NULL,
    nightly_rate  DECIMAL(10,2) NULL,

    -- RSS promised every engineer their own room, in writing. A promise that
    -- lives only in an email gets broken by whoever books the next room, so
    -- it is a column, it defaults to kept, and the hotel board shows it.
    private_room  TINYINT(1) NOT NULL DEFAULT 1,
    status        ENUM('held','booked','checked_in','checked_out','cancelled')
                  NOT NULL DEFAULT 'booked',
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX (placement_id), INDEX (hotel_id, check_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────── travel
-- Flights in and out, the airport pickup, and the daily runs.
CREATE TABLE IF NOT EXISTS travel (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    placement_id INT UNSIGNED NOT NULL,
    direction    ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
    mode         ENUM('flight','drive','bus','train') NOT NULL DEFAULT 'flight',
    carrier      VARCHAR(90) NULL,
    reference    VARCHAR(60) NULL,            -- confirmation / record locator
    depart_from  VARCHAR(120) NULL,
    arrive_at    VARCHAR(120) NULL,
    depart_time  DATETIME NULL,
    arrive_time  DATETIME NULL,
    cost         DECIMAL(10,2) NULL,

    -- "Transportation will be provided from the airport to the hotel."
    pickup_needed TINYINT(1) NOT NULL DEFAULT 1,
    pickup_by     VARCHAR(120) NULL,
    status        ENUM('planned','booked','completed','cancelled') NOT NULL DEFAULT 'planned',
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (placement_id, direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- "Scheduled runs will be provided for food, Walmart, laundry and haircuts."
-- A shuttle is a thing that happens to many people at once, so it is its own
-- row with a seat count, not a field on a placement.
CREATE TABLE IF NOT EXISTS shuttle_runs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id      INT UNSIGNED NOT NULL,
    purpose     ENUM('site','food','walmart','laundry','haircut','airport','other')
                NOT NULL DEFAULT 'site',
    runs_on     DATE NOT NULL,
    depart_time TIME NULL,
    return_time TIME NULL,
    pickup_point VARCHAR(190) NULL,
    driver      VARCHAR(120) NULL,
    seats       SMALLINT UNSIGNED NULL,
    notes       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (job_id, runs_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────── the week
-- Hours are collected per week because the cheque is weekly and the guarantee
-- is weekly. week_ending is always a Saturday.
CREATE TABLE IF NOT EXISTS timesheets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    placement_id  INT UNSIGNED NOT NULL,
    week_ending   DATE NOT NULL,
    hours_worked  DECIMAL(6,2) NOT NULL DEFAULT 0,
    -- Days physically there, which is what the $30 is paid against - not the
    -- days worked. A man who sits in a hotel on a rained-off day still ate.
    per_diem_days TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expenses      DECIMAL(10,2) NOT NULL DEFAULT 0,
    expense_note  VARCHAR(255) NULL,

    status        ENUM('draft','submitted','approved','paid') NOT NULL DEFAULT 'draft',
    approved_by   INT UNSIGNED NULL,
    approved_at   DATETIME NULL,
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_placement_week (placement_id, week_ending),
    INDEX (week_ending, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────── who did what
CREATE TABLE IF NOT EXISTS activity (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(60) NOT NULL,
    entity     VARCHAR(40) NULL,
    entity_id  INT UNSIGNED NULL,
    detail     VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (entity, entity_id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COLLATE=utf8mb4_unicode_ci;

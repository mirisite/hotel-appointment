CREATE DATABASE IF NOT EXISTS hotel_management
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hotel_management;

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(32) NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY customers_username_unique (username),
    UNIQUE KEY customers_email_unique (email),
    KEY customers_active_idx (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS management_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY management_users_username_unique (username),
    UNIQUE KEY management_users_email_unique (email),
    KEY management_users_active_idx (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY announcements_created_idx (created_at),
    CONSTRAINT announcements_author_fk FOREIGN KEY (created_by) REFERENCES management_users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcement_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY announcement_images_path_unique (file_path),
    KEY announcement_images_order_idx (announcement_id, sort_order),
    CONSTRAINT announcement_images_announcement_fk FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotel_settings (
    id TINYINT UNSIGNED NOT NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT hotel_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES management_users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_type ENUM('Standard Room', 'Deluxe Room', 'Family Room', 'Suite', 'Presidential Suite') NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_guests TINYINT UNSIGNED NOT NULL,
    adults_allowed TINYINT UNSIGNED NOT NULL,
    children_allowed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bed_count TINYINT UNSIGNED NOT NULL,
    bed_type ENUM('Single', 'Double', 'Queen', 'King') NOT NULL,
    size_sqm DECIMAL(7,2) NOT NULL,
    availability ENUM('available', 'limited', 'fully_booked') NOT NULL DEFAULT 'available',
    rooms_remaining SMALLINT UNSIGNED NULL,
    amenities JSON NOT NULL,
    description TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY rooms_availability_idx (availability),
    KEY rooms_created_at_idx (created_at),
    CONSTRAINT rooms_created_by_fk FOREIGN KEY (created_by) REFERENCES management_users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_photos_path_unique (file_path),
    KEY room_photos_room_sort_idx (room_id, sort_order),
    CONSTRAINT room_photos_room_fk FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_type_availability (
    room_type ENUM('Standard Room', 'Deluxe Room', 'Family Room', 'Suite', 'Presidential Suite') NOT NULL,
    availability_date DATE NOT NULL,
    available_rooms SMALLINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (room_type, availability_date),
    KEY room_availability_date_idx (availability_date),
    CONSTRAINT room_availability_updated_by_fk FOREIGN KEY (updated_by) REFERENCES management_users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_reference VARCHAR(32) NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    guest_name VARCHAR(120) NOT NULL,
    guest_email VARCHAR(254) NOT NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    adults TINYINT UNSIGNED NOT NULL,
    children TINYINT UNSIGNED NOT NULL DEFAULT 0,
    room_count TINYINT UNSIGNED NOT NULL,
    nights SMALLINT UNSIGNED NOT NULL,
    room_subtotal DECIMAL(12,2) NOT NULL,
    extra_services JSON NOT NULL,
    extra_services_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    service_fee_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    payment_method ENUM('card', 'gcash', 'maya', 'qrph', 'bank_transfer', 'pay_at_hotel') NOT NULL,
    payment_status ENUM('pending', 'unpaid', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    booking_status ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
    paymongo_checkout_session_id VARCHAR(100) NULL,
    paymongo_checkout_url TEXT NULL,
    payment_access_token CHAR(64) NULL,
    paid_at DATETIME NULL,
    confirmation_email_sent_at DATETIME NULL,
    payment_receipt_email_sent_at DATETIME NULL,
    assigned_room_number VARCHAR(512) NULL,
    room_assignment_email_sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bookings_reference_unique (booking_reference),
    UNIQUE KEY bookings_paymongo_session_unique (paymongo_checkout_session_id),
    UNIQUE KEY bookings_payment_access_token_unique (payment_access_token),
    KEY bookings_customer_created_idx (customer_id, created_at),
    KEY bookings_room_dates_idx (room_id, check_in, check_out),
    KEY bookings_assigned_room_dates_idx (assigned_room_number, check_in, check_out),
    CONSTRAINT bookings_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT bookings_room_fk FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB;
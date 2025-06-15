<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'cics_grades');

// Create Connection
try {
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Uncomment to create tables if they don't exist
    initializeDatabase($conn);
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Creates the required tables if they don't exist
 */
function initializeDatabase($conn) {
    // Students table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    // Sections table
    $tableExists = false;
    $result = $conn->query("SHOW TABLES LIKE 'sections'");
    if ($result->rowCount() > 0) {
        $tableExists = true;
    }
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            curriculum VARCHAR(100) NOT NULL,
            year_level VARCHAR(100) NOT NULL,
            section_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    if (!$tableExists) {
        $defaultSections = [
            ['BS InfoTech', '1st', '1A'],
            ['BS InfoTech', '1st', '1B'],
            ['BS InfoTech', '1st', '1C'],
            ['BS InfoTech', '1st', '1D'],
            ['BS InfoTech', '1st', '1E'],
            ['BS InfoTech', '1st', '1F'],
            ['BS InfoTech', '2nd', '2A'],
            ['BS InfoTech', '2nd', '2B'],
            ['BS InfoTech', '2nd', '2C'],
            ['BS InfoTech', '2nd', '2D'],
        ];

        $stmt = $conn->prepare("INSERT INTO sections (curriculum, year_level, section_name) VALUES (?, ?, ?)");
        
        foreach ($defaultSections as $section) {
            $stmt->execute($section);
        }
    }
    
    // Student grades table (with foreign key)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS student_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            section_id INT NOT NULL,
            grade VARCHAR(10) NOT NULL,
            professor VARCHAR(50) NOT NULL,
            subject VARCHAR(50) NOT NULL,
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
}

// Close connection function
function closeDBConnection() {
    global $conn;
    $conn = null;
}
?>
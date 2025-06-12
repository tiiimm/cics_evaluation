<?php
session_start();
require 'vendor/autoload.php';
require_once 'db.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Table;

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$extractedData = [];
$uploadPath = '';
$error = '';
$success = '';

// Helper function to sanitize output
function sanitize($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Handle file upload and processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token. Please try again.";
    } else {
        // Handle file upload
        if (isset($_FILES['gradesheet']) && $_FILES['gradesheet']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['gradesheet'];
            
            // Validate file type
            $allowedTypes = [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            
            if (!in_array($file['type'], $allowedTypes)) {
                $error = "Invalid file type. Please upload a .docx file.";
            } else {
                // Create uploads directory if it doesn't exist
                if (!file_exists('uploads') && !mkdir('uploads', 0755, true)) {
                    $error = "Failed to create upload directory.";
                } else {
                    // Generate unique filename
                    $filename = uniqid() . '_' . basename($file['name']);
                    $uploadPath = 'uploads/' . $filename;
                    
                    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        $error = "Failed to move uploaded file.";
                    } elseif (!file_exists($uploadPath)) {
                        $error = "File not found after upload.";
                    } else {
                        try {
                            // Load the Word document
                            $phpWord = IOFactory::load($uploadPath);
                            
                            // Extract data from document
                            foreach ($phpWord->getSections() as $section) {
                                $elements = $section->getElements();
                                
                                foreach ($elements as $element) {
                                    if ($element instanceof Table) {
                                        $students = parseTableData($element);
                                        if (!empty($students)) {
                                            $extractedData[] = $students;
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $error = "Error processing Word file: " . $e->getMessage();
                            @unlink($uploadPath); // Clean up uploaded file
                        }
                    }
                }
            }
        }
        
        // Handle save to database
        if (isset($_POST['save_to_db']) && isset($_POST['student_data']) && isset($_POST['sections'])) {
            try {
                $studentData = json_decode($_POST['student_data'], true);
                $sectionsData = json_decode($_POST['sections'], true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid data format.");
                }
                
                $conn->beginTransaction();
                $insertedCount = 0;
                
                foreach ($studentData as $sectionIndex => $sectionGroup) {
                    if (!isset($sectionsData[$sectionIndex])) {
                        continue;
                    }
                    
                    $sectionId = (int)$sectionsData[$sectionIndex]['section_id'];
                    $subject = sanitize($sectionsData[$sectionIndex]['subject']);
                    
                    foreach ($sectionGroup as $student) {
                        // Validate student data
                        if (empty($student['name']) || !isset($student['grade'])) {
                            continue;
                        }

                        $existingStmt = $conn->query("SELECT id, name FROM students");
                        $existingStudents = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

                        $matched = false;
                        $nametosave = $student['name'];
                        foreach ($existingStudents as $existing) {
                            $nametosave1array = explode(" ", $student['name']);
                            $nametocheck1array = explode(" ", $existing['name']);

                            $length = min(count($nametosave1array), count($nametocheck1array));
                            if (strlen($nametosave1array[$length-1]) < 3) $length -= 1;
                            else if (strlen($nametocheck1array[$length-1]) < 3) $length -= 1;
                            $total = 0;

                            for ($x = 0; $x < $length; $x++) {
                                similar_text(strtolower($nametosave1array[$x]), strtolower($nametocheck1array[$x]), $percent);
                                $total += $percent;
                            }

                            $average = $length > 0 ? $total / $length : 0;

                            if ($average >= 90) { // Adjust threshold as needed
                                $studentId = (int)$existing['id'];
                                $matched = true;
                                $nametosave = $existing['name'];
                                break;
                            }
                        }
                        
                        $stmt = $conn->prepare("
                            INSERT INTO students (name) 
                            VALUES (?) 
                            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
                        ");
                        $stmt->execute([trim($nametosave)]);
                        $studentId = (int)$conn->lastInsertId();

                        if ($studentId <= 0) {
                            continue;
                        }
                        
                        // Validate grade
                        $grade = $student['grade'];
                        
                        // Insert grade with section and subject
                        $gradeStmt = $conn->prepare("
                            INSERT INTO student_grades 
                            (student_id, section_id, subject, grade, remarks) 
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                            grade = VALUES(grade), remarks = VALUES(remarks)
                        ");
                        $gradeStmt->execute([
                            $studentId,
                            $sectionId,
                            $subject,
                            $grade,
                            !empty($student['remarks']) ? sanitize($student['remarks']) : null
                        ]);
                        
                        $insertedCount++;
                    }
                }
                
                $conn->commit();
                $success = "Successfully saved $insertedCount student grades to database!";
                
                // Clean up uploaded file after successful processing
                if (!empty($uploadPath) && file_exists($uploadPath)) {
                    @unlink($uploadPath);
                }
                
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Database error: " . $e->getMessage() .
                    " in " . $e->getFile() .
                    " on line " . $e->getLine();
            }
        }
    }
}

// Function to parse table data from Word document
function parseTableData(Table $table): array {
    $students = [];
    $rows = $table->getRows();
    
    foreach ($rows as $row) {
        $cells = $row->getCells();
        $columns = [];
        
        foreach ($cells as $cell) {
            $text = '';
            foreach ($cell->getElements() as $cellElement) {
                if (method_exists($cellElement, 'getText')) {
                    $text .= $cellElement->getText() . ' ';
                } elseif (method_exists($cellElement, 'getElements')) {
                    foreach ($cellElement->getElements() as $subElement) {
                        if (method_exists($subElement, 'getText')) {
                            $text .= $subElement->getText() . ' ';
                        }
                    }
                }
            }
            $columns[] = trim($text);
        }
        
        // Skip empty rows or headers
        if (empty($columns) || empty(implode('', $columns))) {
            continue;
        }
        
        // Check different possible column formats
        $studentData = null;
        
        // Format 1: Number, Name, Grade, Remarks
        if (is_numeric($columns[0])) {
            $studentData = [
                'number' => $columns[0],
                'name' => $columns[1],
                'grade' => $columns[2],
                'remarks' => $columns[3] ?? null
            ];
        }
        
        // Validate and add student data
        if ($studentData && 
            !empty($studentData['name']) && 
            !str_contains(strtoupper($studentData['name']), 'NOTHING FOLLOWS')) {
            $students[] = $studentData;
        }
    }
    
    foreach ($rows as $row) {
        $cells = $row->getCells();
        $columns = [];
        
        foreach ($cells as $cell) {
            $text = '';
            foreach ($cell->getElements() as $cellElement) {
                if (method_exists($cellElement, 'getText')) {
                    $text .= $cellElement->getText() . ' ';
                } elseif (method_exists($cellElement, 'getElements')) {
                    foreach ($cellElement->getElements() as $subElement) {
                        if (method_exists($subElement, 'getText')) {
                            $text .= $subElement->getText() . ' ';
                        }
                    }
                }
            }
            $columns[] = trim($text);
        }
        
        // Skip empty rows or headers
        if (empty($columns) || empty(implode('', $columns))) {
            continue;
        }
        
        // Check different possible column formats
        $studentData = null;
        
        // Format 1: Number, Name, Grade, Remarks
        if (count($columns) == 6 && is_numeric($columns[2]) && !is_numeric($columns[0])
            && strpos($columns[2], 'NOTHING FOLLOWS') === false && $columns[2] != ''
            && strpos($columns[3], 'NOTHING FOLLOWS') === false && $columns[3] != '') {
            $studentData = [
                'number' => $columns[2],
                'name' => $columns[3],
                'grade' => $columns[4],
                'remarks' => $columns[5] ?? null
            ];
        }
        elseif (is_numeric($columns[5])
            && strpos($columns[5], 'NOTHING FOLLOWS') === false && $columns[5] != ''
            && strpos($columns[6], 'NOTHING FOLLOWS') === false && $columns[6] != '') {
            $studentData = [
                'number' => $columns[5],
                'name' => $columns[6],
                'grade' => $columns[7],
                'remarks' => $columns[8] ?? null
            ];
        }
        
        // Validate and add student data
        if ($studentData && 
            !empty($studentData['name']) && 
            !str_contains(strtoupper($studentData['name']), 'NOTHING FOLLOWS')) {
            $students[] = $studentData;
        }
    }
    
    return $students;
}

// Get sections from database
$sectionsQuery = $conn->query("SELECT * FROM sections ORDER BY year_level, section_name");
$sections = $sectionsQuery->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Sheet Extractor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .container {
            max-width: 1200px;
            width: 1240px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        h1 {
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background-color: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        .alert-error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        .upload-form {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="file"] {
            display: block;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }
        button, .btn {
            background-color: #3498db;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }
        button:hover, .btn:hover {
            background-color: #2980b9;
        }
        .btn-secondary {
            background-color: #95a5a6;
        }
        .btn-secondary:hover {
            background-color: #7f8c8d;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #3498db;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .section-container {
            margin-bottom: 40px;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            background: white;
        }
        .section-header {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
            align-items: flex-end;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .section-title {
            flex-grow: 1;
            font-size: 1.2em;
            font-weight: bold;
            color: #2c3e50;
        }
        .form-control {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-width: 200px;
            font-size: 16px;
        }
        .action-buttons {
            margin: 30px 0;
            text-align: center;
        }
        .save-btn {
            background-color: #27ae60;
            padding: 12px 25px;
            font-size: 18px;
        }
        .save-btn:hover {
            background-color: #219653;
        }
        .button {
            display: inline-block;
            padding: 8px 16px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .button:hover {
            background-color: #45a049;
        }
        @media (max-width: 768px) {
            .section-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .form-control {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Grade Sheet Extractor</h1>

        <a href="index.php" class="button">Back to Upload</a>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= sanitize($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= sanitize($success) ?></div>
        <?php endif; ?>
        
        <?php if (empty($extractedData)): ?>
            <div class="upload-form">
                <h2>Upload Grade Sheet</h2>
                
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= sanitize($_SESSION['csrf_token']) ?>">
                    
                    <div class="form-group">
                        <label for="gradesheet">Select Word Document (.docx):</label>
                        <input type="file" name="gradesheet" id="gradesheet" accept=".docx" required>
                    </div>
                    
                    <button type="submit">Upload and Extract</button>
                </form>
            </div>
        <?php else: ?>
            <h2>Extracted Grades</h2>
            <p>Review the extracted data below before saving to database.</p>
            
            <form method="post" id="saveForm">
                <input type="hidden" name="csrf_token" value="<?= sanitize($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="save_to_db" value="1">
                <input type="hidden" name="student_data" id="studentDataInput">
                <input type="hidden" name="sections" id="sectionsInput">
                
                <?php foreach ($extractedData as $sectionIndex => $section): ?>
                    <div class="section-container">
                        <div class="section-header">
                            <div class="section-title">Section <?= $sectionIndex + 1 ?></div>
                            
                            <div class="form-group">
                                <label for="section-<?= $sectionIndex ?>">Assign to Section:</label>
                                <select id="section-<?= $sectionIndex ?>" class="form-control section-select" required>
                                    <option value="">Select Section</option>
                                    <?php foreach ($sections as $dbSection): ?>
                                        <option value="<?= sanitize($dbSection['id']) ?>">
                                            <?= sanitize($dbSection['year_level'] . ' ' . $dbSection['section_name']) ?> 
                                            (<?= sanitize($dbSection['curriculum']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="subject-<?= $sectionIndex ?>">Subject:</label>
                                <input type="text" id="subject-<?= $sectionIndex ?>" class="form-control subject-input" 
                                       placeholder="Enter subject name" required>
                            </div>
                        </div>
                        
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Name</th>
                                    <th>Final Grade</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section as $student): ?>
                                    <tr>
                                        <td><?= sanitize($student['number']) ?></td>
                                        <td><?= sanitize($student['name']) ?></td>
                                        <td><?= sanitize($student['grade']) ?></td>
                                        <td><?= !empty($student['remarks']) ? sanitize($student['remarks']) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                
                <div class="action-buttons">
                    <button type="submit" class="save-btn">Save to Database</button>
                    <a href="?" class="btn btn-secondary">Upload Another File</a>
                </div>
            </form>
            
            <script>
                document.getElementById('saveForm').addEventListener('submit', function(e) {
                    // Prepare student data
                    const studentData = <?= json_encode($extractedData) ?>;
                    
                    // Prepare sections data
                    const sectionsData = [];
                    const sectionSelects = document.querySelectorAll('.section-select');
                    const subjectInputs = document.querySelectorAll('.subject-input');
                    
                    sectionSelects.forEach((select, index) => {
                        sectionsData.push({
                            section_id: select.value,
                            subject: subjectInputs[index].value
                        });
                    });
                    
                    // Set hidden inputs
                    document.getElementById('studentDataInput').value = JSON.stringify(studentData);
                    document.getElementById('sectionsInput').value = JSON.stringify(sectionsData);
                });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
require_once 'vendor/autoload.php';
require_once 'db.php';

use setasign\Fpdi\Fpdi;

if (isset($_GET['student_id'])) {
    $studentId = (int)$_GET['student_id'];

    // Fetch subject-grade pairs
    $stmt = $conn->prepare("
        SELECT s.name, g.subject, g.grade
        FROM student_grades g
        JOIN students s ON g.student_id = s.id
        WHERE g.student_id = ?
    ");
    $stmt->execute([$studentId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $name = $results[0]['name'] ?? 'Unknown Student';
    $grades = [];
    foreach ($results as $row) {
        $grades[$row['subject']] = $row['grade'];
    }

    // Load PDF and import page 1
    $pdf = new Fpdi();
    $pdf->AddPage();
    $pdf->setSourceFile('Revised-Prospectus.pdf');
    $tpl = $pdf->importPage(1);
    $pdf->useTemplate($tpl);

    // Setup text
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0); // Blue color

    // Mapping of subject codes to their approximate Y positions (X is constant)
    $positions = [
        'ITCC 103' => [33.5, 188],
        'ITCC 104' => [33.5, 193.5],
        'ITPC 101' => [33.5, 199],
        'CSS' => [33.5, 204],
        'GE 115' => [33.5, 209],
        'GE 116' => [33.5, 214.5],
        'GE Elective 2' => [33.5, 219.5], // e.g., Environmental Science
        'PE' => [33.5, 226],
        'NSTP-CWTS' => [33.5, 232],
        'NSTP-ROTC' => [33.5, 232]
    ];

    foreach ($positions as $subject => [$x, $y]) {
        if (isset($grades[$subject])) {
            $grade = strtoupper(trim($grades[$subject]));

            if (is_numeric($grade)) {
                $grade = number_format((float)$grade, 2); // Formats 2 → 2.00
            }

            $pdf->SetXY($x, $y);
            $pdf->Write(0, $grade);
        }
    }
    $pdf->SetXY(26, 90);
    $pdf->Write(0, strtoupper($name));
    
    // Output PDF
    $pdf->Output('I', "Student_Grades_Page1.pdf");
}

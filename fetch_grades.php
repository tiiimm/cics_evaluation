<?php
require_once 'db.php'; // Your DB connection file

if (isset($_GET['student_id'])) {
    $studentId = (int)$_GET['student_id'];

    $stmt = $conn->prepare("
        SELECT sg.subject, sg.grade, sg.professor, s.section_name 
        FROM student_grades sg
        JOIN sections s ON sg.section_id = s.id
        WHERE sg.student_id = ?
        ORDER BY FIELD(sg.subject, 
            'ITCC 103', 
            'ITCC 104', 
            'ITPC 101', 
            'CSS', 
            'GE 115', 
            'GE 116', 
            'GE Elective 1', 
            'PE', 
            'NSTP-CWTS',
            'NSTP-ROTC')
    ");

    $stmt->execute([$studentId]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($grades)) {
        echo "<p>No grades found for this student.</p>";
    } else {
        // Initialize counters
        $totalGrades = 0;
        $numericGradeSum = 0.0;
        $unsatisfactoryMajor = 0;
        $countNumeric = 0;
        $countFail = 0;
        $countInc = 0;
        $countDrp = 0;

        echo "<table class='table'>";
        echo "<tr><th>Subject</th><th>Grade</th></tr>";
        foreach ($grades as $grade) {
            $subject = htmlspecialchars($grade['subject']);
            $gradeValue = strtoupper(trim($grade['grade']));
            $professor = trim($grade['professor']);
            $section = trim($grade['section_name']);

            echo "<tr>";
            echo "<td>$subject ($professor-$section)</td><td>";

            if (is_numeric($gradeValue)) {
                $numericGradeSum += floatval($gradeValue);
                $countNumeric++;

                if (floatval($gradeValue) == 5.0) {
                    $countFail++;

                    if ($subject == 'ITCC 103' || $subject == 'ITCC 104' || $subject == 'ITPC 101') $unsatisfactoryMajor++;
                }

                echo number_format($gradeValue, 2);
            } else {
                if (str_contains(trim($gradeValue), 'INC')) {
                    $numericGradeSum += floatval(3);
                    $countNumeric++;
                    $countInc++;
                }
                elseif (str_contains(trim($gradeValue), 'DRP')) {
                    $numericGradeSum += floatval(5);
                    $countNumeric++;
                    $countDrp++;

                    if ($subject == 'ITCC 103' || $subject == 'ITCC 104' || $subject == 'ITPC 101') $unsatisfactoryMajor++;
                }
                echo trim($gradeValue);
            }

            echo "</td></tr>";
        }
        echo "</table>";

        // Compute average
        $average = $countNumeric > 0 ? ($numericGradeSum * 3) / ($countNumeric * 3) : null;
        $remarks = "GOOD STANDING";
        $countIncDrp = $countInc + $countDrp;

        // Determine academic standing
        if ($unsatisfactoryMajor >= 2 || $countDrp >= 4) {
        // if ($countFail >= 2 || $countDrp >= 4) {
            // if ($countFail >= 2) $reason = $reason."2 5.0 grade<br>";
            $remarks = "OUTRIGHT DISQUALIFICATION";
            if ($countFail >= 2) $reason = $reason."RETENTION POLICY 4A. 2 or more failing major grades<br>";
                                else if ($unsatisfactoryMajor >= 2) $reason = $reason."2 or more drp/fail major grades<br>";
            if ($countDrp >= 4) $reason = $reason."RETENTION POLICY 4B. 4 DRP<br>";
        }
        elseif (
            // ($average !== null && $average > 2.75) ||
            $countFail == 1 ||
            $countIncDrp >= 3
        ) {
            $remarks = "PROBATION";
            if ($average !== null && $average > 2.75 && $countNumeric>=7) $reason = $reason."RETENTION POLICY 2A. AVERAGE BELOW 2.75<br>";
            if ($countFail == 1) $reason = $reason."RETENTION POLICY 2B. With a 5.0 in any subject<br>";
            if ($countIncDrp >= 3) $reason = $reason."RETENTION POLICY 2C. Total of 3 INC and/or DRP<br>";
        }

        // Display summary
        echo "<hr>";
        if ($countNumeric == 7 || $countNumeric == 8) echo "<p><strong>Average (without PE):</strong> " . ($average !== null ? number_format($average, 2) : "N/A") . "</p>";
        $remarksColor = ($remarks === 'GOOD STANDING') ? 'green' : 'red';
        echo "<p><strong>Remarks:</strong> <span style='color: $remarksColor;'>$remarks</span><br>";
        if($remarks != 'GOOD STANDING')echo "<strong>Reason:</strong><br>$reason</p>";
    }
}
?>

<?php
require_once 'db.php'; // Your DB connection file

if (isset($_GET['student_id'])) {
    $studentId = (int)$_GET['student_id'];

    $stmt = $conn->prepare("
        SELECT subject, grade 
        FROM student_grades 
        WHERE student_id = ?
        ORDER BY FIELD(subject, 
            'ITCC 103', 
            'ITCC 104', 
            'ITPC 101', 
            'CSS', 
            'GE 115', 
            'GE 116', 
            'GE Elective 2', 
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
        $countNumeric = 0;
        $countFail = 0;
        $countInc = 0;
        $countDrp = 0;

        echo "<table class='table'>";
        echo "<tr><th>Subject</th><th>Grade</th></tr>";
        foreach ($grades as $grade) {
            $subject = htmlspecialchars($grade['subject']);
            $gradeValue = strtoupper(trim($grade['grade']));

            echo "<tr>";
            echo "<td>$subject</td><td>";

            if (is_numeric($gradeValue)) {
                $numericGradeSum += floatval($gradeValue);
                $countNumeric++;

                if (floatval($gradeValue) == 5.0) {
                    $countFail++;
                }

                echo number_format($gradeValue, 2);
            } else {
                if (str_contains(trim($gradeValue), 'INC')) {
                    $countInc++;
                }
                elseif (str_contains(trim($gradeValue), 'DRP')) {
                    $countDrp++;
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
        if ($countFail >= 2 || $countDrp >= 4) {
            $remarks = "OUTRIGHT DISQUALIFICATION";
            if ($countFail >= 2) $reason = "2 5.0 grade";
            if ($countIncDrp >= 4) $reason = "RETENTION POLICY 4B. 4 DRP";
        }
        elseif (
            ($average !== null && $average > 2.75) ||
            $countFail == 1 ||
            $countIncDrp >= 3
        ) {
            $remarks = "PROBATION";
            if ($average !== null && $average > 2.75) $reason = "RETENTION POLICY 2A. AVERAGE BELOW 2.75";
            if ($countFail == 1) $reason = "RETENTION POLICY 2B. With a 5.0 in any subject";
            if ($countIncDrp >= 3) $reason = "RETENTION POLICY 2C. Total of 3 INC and/or DRP";
        }

        // Display summary
        echo "<hr>";
        echo "<p><strong>Average:</strong> " . ($average !== null ? number_format($average, 2) : "N/A") . "</p>";
        $remarksColor = ($remarks === 'GOOD STANDING') ? 'green' : 'red';
        echo "<p><strong>Remarks:</strong> <span style='color: $remarksColor;'>$remarks</span><br>";
        if($remarks != 'GOOD STANDING')echo "<strong>Reason:</strong> $reason</p>";
    }
}
?>

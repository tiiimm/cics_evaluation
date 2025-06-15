<?php
require_once 'db.php'; // Include your database connection

try {
    // Query to get students with their latest grades
    // $query = "
    //     SELECT 
    //         s.id AS student_id,
    //         s.name AS student_name,
    //         g.grade,
    //         g.subject,
    //         g.remarks,
    //         g.created_at AS grade_date
    //     FROM 
    //         students s
    //     LEFT JOIN 
    //         (SELECT student_id, grade, subject, remarks, created_at
    //          FROM student_grades
    //          ORDER BY created_at DESC) g
    //     ON 
    //         s.id = g.student_id
    //     GROUP BY 
    //         s.id
    //     ORDER BY 
    //         g.subject ASC
    // ";
    $query = "
        SELECT * FROM students
        ORDER BY 
            name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $students = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Error fetching students: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Student List</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        h1 {
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        h2 {
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            position: sticky;
            top: 0;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .passed {
            color: green;
        }
        .failed {
            color: red;
            font-weight: bold;
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
        .search-container {
            margin: 20px 0;
        }
        #searchInput {
            padding: 8px;
            width: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Student Grades</h1>
        
        <a href="index.php" class="button">Back to Upload</a>
        
        <div class="search-container">
            <input type="text" id="searchInput" placeholder="Search students by name, grade, or status..." onkeyup="searchTable()">
            <span id="resultCount" style="margin-left: 10px; font-weight: bold;"></span>
        </div>

        
        <table id="studentTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student Name</th>
                    <th>Remarks</th> <!-- New column -->
                    <th>Reason</th> <!-- New column -->
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($students) > 0): ?>
                    <?php foreach ($students as $index=>$student): ?>
                        <?php
                            // Fetch remarks for each student
                            $studentId = (int)$student['id'];
                            $stmt = $conn->prepare("
                                SELECT subject, grade 
                                FROM student_grades 
                                WHERE student_id = ?
                            ");
                            $stmt->execute([$studentId]);
                            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            $countFail = 0;
                            $countInc = 0;
                            $countDrp = 0;
                            $unsatisfactoryMajor = 0;
                            $numericGradeSum = 0;
                            $countNumeric = 0;

                            foreach ($grades as $g) {
                                $grade = strtoupper(trim($g['grade']));
                                $subject = strtoupper(trim($g['subject']));
                                if (is_numeric($grade)) {
                                    $numericGradeSum += floatval($grade);
                                    $countNumeric++;
                                    if (floatval($grade) == 5.0) {
                                        $countFail++;

                                        if ($subject == 'ITCC 103' || $subject == 'ITCC 104' || $subject == 'ITPC 101') $unsatisfactoryMajor++;
                                    }
                                } elseif (str_contains($grade, 'INC')) {
                                    $countInc++;
                                } elseif (str_contains($grade, 'DRP')) {
                                    $countDrp++;
                                    if ($subject == 'ITCC 103' || $subject == 'ITCC 104' || $subject == 'ITPC 101') $unsatisfactoryMajor++;
                                }
                            }

                            $average = $countNumeric > 0 ? ($numericGradeSum * 3) / ($countNumeric * 3) : null;
                            $remarks = "GOOD STANDING";
                            $reason = "";
                            $countIncDrp = $countInc + $countDrp;

                            if ($unsatisfactoryMajor >= 2 || $countDrp >= 4) {
                            // if ($countFail >= 2 || $countDrp >= 4) {
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

                            $remarksColor = match($remarks) {
                                "GOOD STANDING" => "green",
                                "PROBATION" => "brown",
                                "UNSATISFACTORY PERFORMANCE IN 2 MAJORS" => "orange",
                                "OUTRIGHT DISQUALIFICATION" => "red",
                                default => "black"
                            };
                        ?>
                        <tr>
                            <td><small><?= htmlspecialchars($index+1) ?></small></td>
                            <td><small><?= htmlspecialchars(strtoupper($student['name'])) ?></small></td>
                            <td style="color: <?= $remarksColor ?>;"><small><?= $remarks ?></small></td>
                            <td><small><?= $reason ?></small></td>
                            <td>
                                <button 
                                    class="btn btn-primary btn-sm view-grades-btn fs-6" 
                                    data-student-id="<?= htmlspecialchars($student['id']) ?>" 
                                    data-student-name="<?= htmlspecialchars(strtoupper($student['name'])) ?>"
                                >
                                    View Grades
                                </button>
                                <button 
                                    class="btn btn-primary btn-sm export-grades-btn fs-6" 
                                    data-student-id="<?= htmlspecialchars($student['id']) ?>" 
                                    data-student-name="<?= htmlspecialchars(strtoupper($student['name'])) ?>"
                                >
                                    Print Grades
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center;">No students found in database</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>


        <div class="modal fade" id="gradesModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Grades for <span id="studentName"></span></h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="gradesContainer">
                        <!-- Grades will be loaded here via AJAX -->
                        Loading grades...
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function searchTable() {
                // document.getElementById("resultCount").textContent = filter ? `Showing ${count} result(s)` : "";
                let input = document.getElementById("searchInput");
                let filter = input.value.toUpperCase();
                let table = document.getElementById("studentTable");
                let tr = table.getElementsByTagName("tr");

                let count = 0;

                for (let i = 1; i < tr.length; i++) { // skip the header row
                    let found = false;
                    let td = tr[i].getElementsByTagName("td");
                    
                    for (let j = 0; j < td.length - 1; j++) {
                        if (td[j]) {
                            let txtValue = td[j].textContent || td[j].innerText;
                            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                                found = true;
                                break;
                            }
                        }
                    }
                    
                    if (found) {
                        tr[i].style.display = "";
                        count++;
                    } else {
                        tr[i].style.display = "none";
                    }
                }

                // Update result count
                document.getElementById("resultCount").textContent = `Showing ${count} result(s)`;
            }
            document.addEventListener('DOMContentLoaded', function() {
                // When "View Grades" button is clicked
                document.querySelectorAll('.view-grades-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const studentId = this.getAttribute('data-student-id');
                        const studentName = this.getAttribute('data-student-name');
                        
                        // Set student name in modal title
                        document.getElementById('studentName').textContent = studentName;
                        
                        // Fetch grades via AJAX (using Fetch API)
                        fetch(`fetch_grades.php?student_id=${encodeURIComponent(studentId)}`)
                            .then(response => {
                                if (!response.ok) throw new Error('Failed to load grades');
                                return response.text();
                            })
                            .then(html => {
                                document.getElementById('gradesContainer').innerHTML = html;
                            })
                            .catch(error => {
                                document.getElementById('gradesContainer').innerHTML = 'Failed to load grades.';
                                console.error(error);
                            });
                        
                        // Show the modal (assuming Bootstrap 5+)
                        const modal = new bootstrap.Modal(document.getElementById('gradesModal'));
                        modal.show();
                    });
                });
                document.querySelectorAll('.export-grades-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const studentId = this.getAttribute('data-student-id');

                        // Open PDF in a new tab/window
                        const url = `export_grades.php?student_id=${encodeURIComponent(studentId)}`;
                        window.open(url, '_blank'); // Open in a new tab
                    });
                });
            });
        </script>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Close the database connection
closeDBConnection();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Gradesheet</title>
    <style>
        .button-container {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        button {
            padding: 8px 16px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <h2>Upload Gradesheet (.docx)</h2>
    <form action="extract.php" method="POST" enctype="multipart/form-data">
        <div class="button-container">
            <button type="button" onclick="window.location.href='extract.php'">Upload Gradesheet</button>
            <button type="button" onclick="window.location.href='student_list.php'">View Student List</button>
        </div>
    </form>
</body>
</html>
<?php
// birthday_system01/add_wish.php

// Initialize the session
session_start();

// Check if the user is logged in, if not then redirect him to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Include config file
require_once "config.php";

// Define variables and initialize with empty values
$title = $wish_message = $photo_path = "";
$title_err = $wish_message_err = $photo_err = "";

// Function to process Summernote content (extract Base64 images to files)
function processSummernoteContent($html) {
    // If input is empty, return as is
    if (empty($html)) return $html;

    $dom = new DOMDocument();
    // Suppress warnings for malformed HTML (common with user input)
    libxml_use_internal_errors(true);
    
    // Load HTML. The mb_convert_encoding helps with UTF-8 characters.
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    $images = $dom->getElementsByTagName('img');

    foreach ($images as $img) {
        $src = $img->getAttribute('src');

        // Check if src is a Base64 image
        if (preg_match('/^data:image\/(\w+);base64,/', $src, $type)) {
            $data = substr($src, strpos($src, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif

            // Allow only specific image types
            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) {
                continue;
            }

            $data = base64_decode($data);

            if ($data === false) {
                continue;
            }

            // Create 'uploads' directory if it doesn't exist
            if (!file_exists('uploads')) {
                mkdir('uploads', 0777, true);
            }

            // Generate unique name
            $new_name = 'content_' . uniqid() . '.' . $type;
            $upload_path = 'uploads/' . $new_name;

            // Save file
            file_put_contents($upload_path, $data);

            // Update img src to the new file path
            $img->setAttribute('src', $upload_path);
            
            // Optional: Add img-fluid class for Bootstrap responsiveness
            $class = $img->getAttribute('class');
            $img->setAttribute('class', $class . ' img-fluid');
        }
    }

    // Return the processed HTML
    return $dom->saveHTML();
}

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // 1. Validate Title
    if(empty(trim($_POST["title"]))){
        $title_err = "Please enter a greeting title.";
    } else{
        $title = trim($_POST["title"]);
    }

    // 2. Validate and Process Wish Message (Rich Text)
    if(isset($_POST["wish_message"])){
        $input_raw_message = $_POST["wish_message"];
        
        // Check if message is essentially empty (stripping tags)
        if(empty(trim(strip_tags($input_raw_message)))){
            $wish_message_err = "Please enter a wish message.";
        } else {
            // Process the content: Extract Base64 images -> Save to uploads/ -> Replace Src
            $wish_message = processSummernoteContent($input_raw_message);
        }
    } else {
        $wish_message_err = "Please enter a wish message.";
    }

    // 3. Handle Optional Cover Photo Upload (The separate file input)
    if(isset($_FILES["photo"]) && $_FILES["photo"]["error"] == 0){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $filename = $_FILES["photo"]["name"];
        $filetype = $_FILES["photo"]["type"];
        $filesize = $_FILES["photo"]["size"];

        // Verify file extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if(!array_key_exists(strtolower($ext), $allowed)) $photo_err = "Error: Please select a valid file format (JPG, JPEG, PNG, GIF).";

        // Verify file size - 5MB maximum
        $maxsize = 5 * 1024 * 1024;
        if($filesize > $maxsize) $photo_err = "Error: File size is larger than the allowed limit (5MB).";

        // Verify MIME type of the file
        if(in_array($filetype, $allowed)){
            // Ensure uploads directory exists
            if (!file_exists("uploads")) {
                mkdir("uploads", 0777, true);
            }

            // Create unique filename to prevent overwrites
            $new_filename = 'cover_' . uniqid() . "." . $ext;
            $destination = "uploads/" . $new_filename;

            if(move_uploaded_file($_FILES["photo"]["tmp_name"], $destination)){
                $photo_path = $destination;
            } else {
                $photo_err = "File upload failed. Please try again.";
            }
        } else{
            $photo_err = "Error: There was a problem with your file upload.";
        }
    }

    // Check input errors before inserting in database
    if(empty($title_err) && empty($wish_message_err) && empty($photo_err)){
        // Prepare an insert statement
        $sql = "INSERT INTO wishes (title, wish_message, photo_path) VALUES (?, ?, ?)";

        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "sss", $param_title, $param_wish_message, $param_photo_path);

            // Set parameters
            $param_title = $title;
            $param_wish_message = $wish_message; // This now contains the modified HTML with real file paths
            $param_photo_path = $photo_path;

            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Records created successfully. Redirect to landing page (PRG Pattern)
                header("location: wishes.php");
                exit();
            } else{
                echo "Oops! Something went wrong. Please try again later. " . mysqli_error($link);
            }
            // Close statement
            mysqli_stmt_close($stmt);
        }
    }

    // Close connection
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Wish - Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    
    <style>
        /* Shared Dashboard Design Language */
        body {
            /* Background Image Update */
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
        }

        /* Loading Icon Styles */
        #loader {
            position: fixed;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            background-color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: opacity 0.5s ease;
        }

        .spinner-container {
            position: relative;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .spinner {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 8px solid #f3f3f3;
            /* Colorful spinner accent */
            border-top: 8px solid #28a745; 
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 2;
        }

        .loader-logo {
            width: 45px;
            height: auto;
            z-index: 1;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Main Container Styling - Glassmorphism */
        .wrapper {
            width: 100%;
            max-width: 900px;
            margin: 50px auto;
            padding: 40px;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        /* Header Section */
        .page-header {
            border-bottom: 2px solid #eee;
            margin-bottom: 30px;
            padding-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h2 {
            color: #333;
            font-weight: 700;
            margin: 0;
            font-size: 1.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .page-header p {
            margin: 5px 0 0;
            color: #6c757d;
        }

        /* Form Styling */
        label {
            font-weight: 600;
            color: #495057;
        }

        .form-control, .custom-file-label {
            border-radius: 8px;
            height: auto;
            border: 1px solid #ced4da;
        }

        .note-editor.note-frame {
            border-radius: 8px;
            border: 1px solid #ced4da;
            box-shadow: none;
        }
        
        .note-toolbar {
            border-radius: 8px 8px 0 0;
        }

        .custom-file-input:focus ~ .custom-file-label {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }

        /* Buttons */
        .btn-submit {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            border: none;
            border-radius: 50px;
            padding: 12px 35px;
            font-weight: 600;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            transition: all 0.3s ease;
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            color: white;
        }

        .btn-cancel {
            background: transparent;
            border: 2px solid #6c757d;
            color: #6c757d;
            border-radius: 50px;
            padding: 10px 30px;
            font-weight: 600;
            margin-left: 10px;
            transition: all 0.3s;
        }

        .btn-cancel:hover {
            background: #6c757d;
            color: white;
            text-decoration: none;
        }

        /* Responsive Fixes */
        @media (max-width: 768px) {
            .wrapper {
                margin: 20px auto;
                padding: 25px;
            }
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            .btn-action-group {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .btn-cancel {
                margin-left: 0;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div id="loader">
        <div class="spinner-container">
            <div class="spinner"></div>
            <img src="BWU_logo.png" alt="Loading..." class="loader-logo">
        </div>
    </div>

    <div class="container">
        <div class="wrapper">
            <div class="page-header">
                <div>
                    <h2><i class="fas fa-pen-fancy text-success mr-2" style="opacity: 0.7;"></i>Write a Wish</h2>
                    <p>Compose a beautiful birthday message. Images dragged here will be uploaded automatically.</p>
                </div>
                <div class="d-none d-md-block">
                    <i class="fas fa-birthday-cake fa-3x text-secondary" style="opacity: 0.1;"></i>
                </div>
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Greeting Title</label>
                    <input type="text" name="title" class="form-control <?php echo (!empty($title_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $title; ?>" placeholder="e.g. Happy Birthday John!">
                    <div class="invalid-feedback"><?php echo $title_err; ?></div>
                </div>

                <div class="form-group">
                    <label for="summernote">Wish Message</label>
                    <textarea id="summernote" name="wish_message"><?php echo $wish_message; ?></textarea>
                    <small class="form-text text-muted">
                        <i class="fas fa-info-circle mr-1"></i> <strong>Drag & Drop</strong> images directly into the editor. They will be uploaded to the server when you submit.
                    </small>
                    <?php if(!empty($wish_message_err)): ?>
                        <div class="alert alert-danger mt-2 py-1 px-3"><?php echo $wish_message_err; ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group mt-5 btn-action-group">
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-paper-plane mr-2"></i> Submit Wish
                    </button>
                    <a href="wishes.php" class="btn btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>

    <script>
        // Page Loading Script
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() {
                loader.style.display = 'none';
            }, 500);
        });

        $(document).ready(function() {
            // Initialize Summernote
            $('#summernote').summernote({
                placeholder: 'Write your birthday wish here... (Drag and drop images enabled)',
                tabsize: 2,
                height: 300,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['fontname', ['fontname']], // Added font options
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });

            // Custom File Input Label Update
            $(".custom-file-input").on("change", function() {
                var fileName = $(this).val().split("\\").pop();
                $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
            });
        });
    </script>
</body>
</html>
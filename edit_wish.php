<?php
// birthday_system01/edit_wish.php

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
    if (empty($html)) return $html;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    $images = $dom->getElementsByTagName('img');

    foreach ($images as $img) {
        $src = $img->getAttribute('src');

        if (preg_match('/^data:image\/(\w+);base64,/', $src, $type)) {
            $data = substr($src, strpos($src, ',') + 1);
            $type = strtolower($type[1]);

            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) continue;

            $data = base64_decode($data);
            if ($data === false) continue;

            if (!file_exists('uploads')) mkdir('uploads', 0777, true);

            $new_name = 'content_' . uniqid() . '.' . $type;
            $upload_path = 'uploads/' . $new_name;

            file_put_contents($upload_path, $data);
            $img->setAttribute('src', $upload_path);
            
            $class = $img->getAttribute('class');
            $img->setAttribute('class', $class . ' img-fluid');
        }
    }
    return $dom->saveHTML();
}

// Processing form data when form is submitted
if(isset($_POST["id"]) && !empty($_POST["id"])){
    
    $id = $_POST["id"];
    // Retrieve existing photo path from hidden field
    $existing_photo_path = $_POST["existing_photo_path"];
    $photo_path = $existing_photo_path; 

    // 1. Validate Title
    if(empty(trim($_POST["title"]))){
        $title_err = "Please enter a title.";
    } else{
        $title = trim($_POST["title"]);
    }

    // 2. Validate Wish Message
    if(isset($_POST["wish_message"])){
        $input_raw_message = $_POST["wish_message"];
        if(empty(trim(strip_tags($input_raw_message))) && !preg_match('/<img/', $input_raw_message)){
             $wish_message_err = "Please enter a wish message.";
        } else {
            $wish_message = processSummernoteContent($input_raw_message);
        }
    } else {
        $wish_message_err = "Please enter a wish message.";
    }

    // 3. Handle Photo Update or Deletion
    // Priority 1: New File Upload (Replaces old)
    if(isset($_FILES["photo"]) && $_FILES["photo"]["error"] == 0){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $filename = $_FILES["photo"]["name"];
        $filetype = $_FILES["photo"]["type"];
        $filesize = $_FILES["photo"]["size"];

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if(!array_key_exists(strtolower($ext), $allowed)) $photo_err = "Error: Please select a valid file format (JPG, JPEG, PNG, GIF).";
        
        $maxsize = 5 * 1024 * 1024; // 5MB limit
        if($filesize > $maxsize) $photo_err = "Error: File size is larger than the allowed limit (5MB).";

        if(in_array($filetype, $allowed)){
            if (!file_exists("uploads")) mkdir("uploads", 0777, true);

            $new_filename = 'cover_' . uniqid() . "." . $ext;
            $destination = "uploads/" . $new_filename;

            if(move_uploaded_file($_FILES["photo"]["tmp_name"], $destination)){
                // Delete old photo
                if(!empty($existing_photo_path) && file_exists($existing_photo_path)){
                    unlink($existing_photo_path);
                }
                $photo_path = $destination;
            } else {
                $photo_err = "File upload failed. Please try again.";
            }
        } else{
            $photo_err = "Error: There was a problem with your file upload.";
        }
    }
    // Priority 2: Delete Existing Photo (Only if no new file uploaded)
    elseif(isset($_POST['delete_photo']) && $_POST['delete_photo'] == '1') {
        if(!empty($existing_photo_path) && file_exists($existing_photo_path)){
            unlink($existing_photo_path);
        }
        $photo_path = ""; 
    }

    // Check input errors before updating in database
    if(empty($title_err) && empty($wish_message_err) && empty($photo_err)){
        // Update Query now includes title
        $sql = "UPDATE wishes SET title=?, wish_message=?, photo_path=? WHERE id=?";

        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "sssi", $param_title, $param_wish_message, $param_photo_path, $param_id);

            $param_title = $title;
            $param_wish_message = $wish_message;
            $param_photo_path = empty($photo_path) ? NULL : $photo_path;
            $param_id = $id;

            if(mysqli_stmt_execute($stmt)){
                // Post-Redirect-Get (PRG)
                header("location: wishes.php");
                exit();
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Close connection
    mysqli_close($link);

} else {
    // Check existence of id parameter before processing further
    if(isset($_GET["id"]) && !empty(trim($_GET["id"]))){
        $id =  trim($_GET["id"]);
        
        $sql = "SELECT * FROM wishes WHERE id = ?";
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $param_id);
            $param_id = $id;
            
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if(mysqli_num_rows($result) == 1){
                    $row = mysqli_fetch_array($result, MYSQLI_ASSOC);
                    $title = $row["title"];
                    $wish_message = $row["wish_message"];
                    $photo_path = $row["photo_path"];
                } else{
                    header("location: error.php");
                    exit();
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    } else{
        header("location: error.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Wish - Birthday System</title>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    
    <style>
        body {
            /* Background Image Update */
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
        }

        /* =====================
           LOADER WITH LOGO
           ===================== */
        #loader {
            position: fixed;
            inset: 0;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s ease;
        }

        /* Container to center items */
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
            border-top: 8px solid #ffc107; /* Warning Color for Edit */
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

        .wrapper {
            width: 100%;
            max-width: 900px;
            margin: 50px auto;
            padding: 40px;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

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

        label {
            font-weight: 600;
            color: #495057;
        }

        .form-control, .custom-file-label {
            border-radius: 8px;
            height: auto;
            border: 1px solid #ced4da;
        }

        /* Summernote Overrides */
        .note-editor.note-frame {
            border-radius: 8px;
            border: 1px solid #ced4da;
            box-shadow: none;
        }
        
        .note-toolbar {
            border-radius: 8px 8px 0 0;
        }

        .btn-submit {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            border: none;
            border-radius: 50px;
            padding: 12px 35px;
            font-weight: 600;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
            transition: all 0.3s ease;
            color: #212529;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
            background: linear-gradient(135deg, #e0a800 0%, #d39e00 100%);
            color: #212529;
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
        
        .current-photo-preview {
            max-width: 200px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 4px;
            object-fit: cover;
        }
        
        .custom-control-label::before {
            border-color: #dc3545;
        }
        .custom-control-input:checked ~ .custom-control-label::before {
            border-color: #dc3545;
            background-color: #dc3545;
        }

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
                    <h2><i class="fas fa-edit text-warning mr-2"></i>Edit Wish</h2>
                    <p class="text-muted mb-0">Update the title, message, or cover photo.</p>
                </div>
            </div>
            
            <form action="<?php echo htmlspecialchars(basename($_SERVER['REQUEST_URI'])); ?>" method="post" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Wish Title</label>
                    <input type="text" name="title" class="form-control <?php echo (!empty($title_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($title); ?>" placeholder="e.g. Birthday Greeting, Happy 20th!">
                    <?php if(!empty($title_err)): ?>
                        <div class="invalid-feedback"><?php echo $title_err; ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="summernote">Wish Message</label>
                    <textarea id="summernote" name="wish_message"><?php echo $wish_message; ?></textarea>
                    <?php if(!empty($wish_message_err)): ?>
                        <div class="alert alert-danger mt-2 py-1 px-3"><?php echo $wish_message_err; ?></div>
                    <?php endif; ?>
                </div>

                  

                <input type="hidden" name="id" value="<?php echo $id; ?>"/>
                <input type="hidden" name="existing_photo_path" value="<?php echo htmlspecialchars($photo_path); ?>"/>

                <div class="form-group mt-5 btn-action-group">
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-save mr-2"></i> Update Wish
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
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() {
                loader.style.display = 'none';
            }, 500);
        });

        $(document).ready(function() {
            $('#summernote').summernote({
                placeholder: 'Edit your wish here...',
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

            $(".custom-file-input").on("change", function() {
                var fileName = $(this).val().split("\\").pop();
                $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
                if(fileName) {
                    $('#deletePhoto').prop('checked', false);
                }
            });
        });
    </script>
</body>
</html>
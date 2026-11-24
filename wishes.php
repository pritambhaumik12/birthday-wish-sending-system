<?php
// birthday_system01/wishes.php

// Initialize the session
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Include config file
require_once "config.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wish Library - Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
        }

        /* Loading Icon */
        #loader {
            position: fixed; left: 0; top: 0; width: 100%; height: 100%; z-index: 9999;
            background-color: #fff; display: flex; justify-content: center; align-items: center; transition: opacity 0.5s ease;
        }
        .spinner-container { position: relative; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; }
        .spinner {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            border: 8px solid #f3f3f3; border-top: 8px solid #e83e8c; 
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .wrapper {
            width: 100%; max-width: 1200px; margin: 50px auto; padding: 40px;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 2px solid #eee; margin-bottom: 30px; padding-bottom: 15px;
        }
        .page-header h2 { color: #333; font-weight: 700; margin: 0; font-size: 1.8rem; text-transform: uppercase; letter-spacing: 1px; }
        .page-header p { margin: 5px 0 0; color: #6c757d; }

        /* Wish Cards */
        .wish-card {
            background: #fff; border: none; border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%; display: flex; flex-direction: column; overflow: hidden;
            border-top: 4px solid #e83e8c;
        }
        .wish-card:hover { transform: translateY(-5px); box-shadow: 0 12px 20px rgba(0,0,0,0.1); }

        .wish-header { padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid #eee; }
        .wish-header h5 { margin: 0; font-weight: 600; color: #333; font-size: 1.1rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .wish-body { padding: 20px; flex-grow: 1; }
        .wish-content {
            font-size: 0.95rem; color: #555; line-height: 1.6;
            background-color: #fff0f3; padding: 15px; border-radius: 8px;
            border-left: 3px solid #e83e8c; margin-bottom: 15px;
            max-height: 200px; overflow-y: auto;
        }
        .wish-content::-webkit-scrollbar { width: 6px; }
        .wish-content::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .wish-content::-webkit-scrollbar-thumb { background: #e83e8c; border-radius: 4px; }
        .wish-content::-webkit-scrollbar-thumb:hover { background: #d63384; }

        .wish-photo-container { text-align: center; margin-top: 15px; border-radius: 8px; overflow: hidden; border: 1px solid #eee; }
        .wish-photo-container img { max-width: 100%; height: auto; max-height: 200px; object-fit: cover; }

        .wish-footer { padding: 15px 20px; border-top: 1px solid #eee; background-color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .date-text { font-size: 0.8rem; color: #999; }
        .action-buttons { display: flex; }

        /* Buttons */
        .btn-custom-add {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%); color: white; border: none;
            border-radius: 50px; padding: 10px 25px; font-weight: 600;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3); transition: all 0.3s;
        }
        .btn-custom-add:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); color: white; background: linear-gradient(135deg, #218838 0%, #1e7e34 100%); }

        .btn-custom-trash {
            background: transparent; border: 2px solid #dc3545; color: #dc3545;
            border-radius: 50px; padding: 8px 20px; font-weight: 600; margin-right: 10px; transition: all 0.3s;
        }
        .btn-custom-trash:hover { background: #dc3545; color: white; text-decoration: none; }

        .btn-custom-back {
            background: transparent; border: 2px solid #6c757d; color: #6c757d;
            border-radius: 50px; padding: 8px 20px; font-weight: 600; margin-right: 10px; transition: all 0.3s;
        }
        .btn-custom-back:hover { background: #6c757d; color: white; text-decoration: none; }

        .btn-icon { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.2s; margin-left: 5px; cursor: pointer; border: none; outline: none; }
        .btn-view-icon { color: #17a2b8; background: rgba(23, 162, 184, 0.1); }
        .btn-view-icon:hover { background: #17a2b8; color: white; transform: scale(1.1); }
        .btn-edit-icon { color: #ffc107; background: rgba(255, 193, 7, 0.1); }
        .btn-edit-icon:hover { background: #ffc107; color: white; transform: scale(1.1); }
        .btn-delete-icon { color: #dc3545; background: rgba(220, 53, 69, 0.1); }
        .btn-delete-icon:hover { background: #dc3545; color: white; transform: scale(1.1); }

        /* Modal Styles */
        .modal-header-delete { background-color: #dc3545; color: white; }
        .modal-icon-delete { font-size: 3.5rem; color: #dc3545; opacity: 0.2; margin-bottom: 15px; }

        /* Email Modal */
        .email-container { width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e0e0e0; font-family: Arial, sans-serif; color: #333333; }
        .email-header { background-color: #f8f9fa; padding: 20px; text-align: center; border-bottom: 3px solid #e83e8c; }
        .email-body { padding: 30px; line-height: 1.6; }
        .email-photo img { max-width: 100%; height: auto; display: block; margin: 0 auto; }
        .email-footer { background-color: #f1f1f1; padding: 15px; text-align: center; font-size: 12px; color: #888; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; gap: 15px; }
            .header-buttons { width: 100%; display: flex; flex-direction: column; gap: 10px; }
            .btn-custom-back, .btn-custom-trash, .btn-custom-add { width: 100%; margin-right: 0; text-align: center; }
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

    <div class="container-fluid">
        <div class="wrapper">
            <div class="page-header">
                <div>
                    <h2><i class="fas fa-birthday-cake text-danger mr-2" style="opacity: 0.7;"></i>Wish Library</h2>
                    <p class="text-muted">Browse and manage birthday greetings templates.</p>
                </div>
                <div class="header-buttons">
                    <a href="dashboard.php" class="btn btn-custom-back">
                        <i class="fas fa-arrow-left mr-2"></i>Dashboard
                    </a>
                    <a href="trash_wishes.php" class="btn btn-custom-trash">
                        <i class="fas fa-trash-alt mr-2"></i>View Trash
                    </a>
                    <a href="add_wish.php" class="btn btn-custom-add">
                        <i class="fas fa-plus mr-2"></i>Add New Wish
                    </a>
                </div>
            </div>

            <div class="row">
                <?php
                // FILTER: Only show active wishes
                $sql = "SELECT * FROM wishes WHERE is_deleted = 0 ORDER BY created_at DESC";
                if($result = mysqli_query($link, $sql)){
                    if(mysqli_num_rows($result) > 0){
                        while($row = mysqli_fetch_array($result)){
                            $date = date("F j, Y, g:i a", strtotime($row['created_at']));
                            
                            echo '<div class="col-lg-4 col-md-6 mb-4">';
                                echo '<div class="wish-card" id="wish-card-'.$row['id'].'">';
                                    
                                    echo '<div class="wish-header">';
                                        echo '<h5 title="'.htmlspecialchars($row['title']).'">';
                                        echo '<span class="badge badge-light text-muted border mr-2">#' . $row['id'] . '</span>';
                                        echo htmlspecialchars($row['title']);
                                        echo '</h5>';
                                    echo '</div>';

                                    echo '<div class="wish-body">';
                                        echo '<div class="wish-content source-message">' . $row['wish_message'] . '</div>';
                                        if (!empty($row['photo_path'])) {
                                            echo '<div class="wish-photo-container source-photo"><img src="' . htmlspecialchars($row['photo_path']) . '" alt="Wish Photo"></div>';
                                        } else {
                                            echo '<div class="wish-photo-container source-photo d-none"></div>';
                                        }
                                    echo '</div>';

                                    echo '<div class="wish-footer">';
                                        echo '<div class="date-text"><i class="far fa-clock mr-1"></i>' . $date . '</div>';
                                        echo '<div class="action-buttons">';
                                            echo '<button type="button" class="btn-icon btn-view-icon view-wish-btn" data-id="'.$row['id'].'" data-title="'.htmlspecialchars($row['title']).'" title="View Preview" data-toggle="tooltip"><i class="fas fa-eye"></i></button>';
                                            echo '<a href="edit_wish.php?id='. $row['id'] .'" title="Edit" data-toggle="tooltip" class="btn-icon btn-edit-icon"><i class="fas fa-pencil-alt"></i></a>';
                                            echo '<button type="button" class="btn-icon btn-delete-icon delete-wish-btn" data-id="'. $row['id'] .'" data-title="'. htmlspecialchars($row['title']) .'" title="Delete" data-toggle="tooltip"><i class="fas fa-trash-alt"></i></button>';
                                        echo '</div>';
                                    echo '</div>';

                                echo '</div>';
                            echo '</div>';
                        }
                        mysqli_free_result($result);
                    } else{
                        echo '<div class="col-12 text-center py-5"><div class="text-muted"><i class="far fa-folder-open fa-4x mb-3 text-secondary" style="opacity: 0.3;"></i><h4>No Wishes Found</h4><p>The library is empty.</p></div></div>';
                    }
                } else{
                    echo '<div class="col-12"><div class="alert alert-danger">Oops! Something went wrong.</div></div>';
                }
                mysqli_close($link);
                ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="emailPreviewModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-body p-0">
                    <div class="email-container">
                        <div class="email-header"><h2 id="modal-email-title"></h2></div>
                        <div class="email-body">
                           
                            <div id="modal-email-message" class="email-message"></div>
                            <div id="modal-email-photo" class="email-photo" style="display: none;"></div>
                           
                        </div>
                        <div class="email-footer"><p>&copy; <?php echo date("Y"); ?> Birthday System.</p></div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-delete">
                    <h5 class="modal-title">Delete Wish?</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body text-center pt-4 pb-4">
                    <i class="fas fa-trash-alt modal-icon-delete"></i>
                    <p class="mb-2">Move this wish to Trash?</p>
                    <h5 class="font-weight-bold text-dark mb-3" id="delete-wish-title"></h5>
                    <p class="text-muted small">You can restore it later.</p>
                </div>
                <div class="modal-footer bg-light justify-content-center">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <a href="#" id="confirm-delete-btn" class="btn btn-danger rounded-pill px-4 font-weight-bold">Yes, Move to Trash</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() { loader.style.display = 'none'; }, 500);
        });

        $(function () { $('[data-toggle="tooltip"]').tooltip() });

        $(document).ready(function() {
            $('.view-wish-btn').click(function() {
                var card = $('#wish-card-' + $(this).data('id'));
                $('#modal-email-title').text($(this).data('title'));
                $('#modal-email-message').html(card.find('.source-message').html());
                
                var photoContainer = $('#modal-email-photo').empty();
                if(card.find('.source-photo img').length > 0) {
                    photoContainer.append(card.find('.source-photo img').clone()).show();
                } else {
                    photoContainer.hide();
                }
                $('#emailPreviewModal').modal('show');
            });

            $('.delete-wish-btn').click(function() {
                $('#delete-wish-title').text($(this).data('title'));
                $('#confirm-delete-btn').attr('href', 'delete_wish.php?id=' + $(this).data('id'));
                $('#deleteModal').modal('show');
            });
        });
    </script>
</body>
</html>
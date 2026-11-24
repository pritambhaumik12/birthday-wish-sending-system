<?php
// birthday_system01/trash_wishes.php

// Initialize the session
session_start();

// Check login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash Bin (Wishes) - Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* =====================
           GLOBAL STYLES
           ===================== */
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
            border: 8px solid #f3f3f3; border-top: 8px solid #dc3545; /* Red for Trash */
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* Wrapper */
        .wrapper {
            width: 100%; max-width: 1200px; margin: 40px auto; padding: 30px;
            height: 100%; max-height: auto;
            background-color: rgba(255, 255, 255, 0.96);
            border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 2px solid #eee; margin-bottom: 25px; padding-bottom: 15px;
        }
        .page-header h2 { color: #dc3545; font-weight: 700; margin: 0; font-size: 1.8rem; }
        .btn-back { border-radius: 30px; padding: 8px 20px; border: 2px solid #6c757d; background: transparent; color: #6c757d; font-weight: 600; transition: all 0.3s; }
        .btn-back:hover { background: #6c757d; color: white; text-decoration: none; }

        /* Bulk Actions Bar */
        .bulk-actions {
            margin-bottom: 25px; padding: 15px 20px; background: #fff3cd;
            border-radius: 12px; border: 1px solid #ffeeba;
            display: flex; align-items: center; gap: 15px; flex-wrap: wrap;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .btn-bulk-restore { background-color: #28a745; color: white; border: none; font-weight: 600; border-radius: 50px; padding: 6px 20px; }
        .btn-bulk-delete { background-color: #dc3545; color: white; border: none; font-weight: 600; border-radius: 50px; padding: 6px 20px; }

        /* =====================
           CARD STYLES (MATCHING WISHES.PHP)
           ===================== */
        .wish-card {
            background: #fff; border: none; border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%; display: flex; flex-direction: column; overflow: hidden;
            border-top: 4px solid #dc3545; /* Red accent for Trash */
            position: relative;
        }
        .wish-card:hover { transform: translateY(-5px); box-shadow: 0 12px 20px rgba(0,0,0,0.1); }
        
        /* Selected State */
        .wish-card.selected { border: 2px solid #dc3545; background-color: #fff5f5; }

        .wish-header {
            padding: 12px 15px; background-color: #f8f9fa; border-bottom: 1px solid #eee;
            display: flex; align-items: center;
        }
        
        /* Checkbox Styling */
        .card-checkbox {
            transform: scale(1.3); margin-right: 12px; cursor: pointer; accent-color: #dc3545;
        }

        .wish-header h5 {
            margin: 0; font-weight: 600; color: #333; font-size: 1.05rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;
        }

        .wish-body { padding: 15px; flex-grow: 1; }
        
        .wish-content {
            font-size: 0.9rem; color: #666; line-height: 1.5;
            background-color: #f8f9fa; padding: 12px; border-radius: 8px;
            border-left: 3px solid #ccc; margin-bottom: 10px;
            max-height: 150px; overflow-y: auto;
        }
        /* Scrollbar */
        .wish-content::-webkit-scrollbar { width: 5px; }
        .wish-content::-webkit-scrollbar-track { background: #f1f1f1; }
        .wish-content::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

        .wish-photo-container {
            text-align: center; margin-top: 10px; border-radius: 8px; overflow: hidden; border: 1px solid #eee;
            background: #eee; height: 120px; display: flex; align-items: center; justify-content: center;
        }
        .wish-photo-container img { max-width: 100%; max-height: 100%; object-fit: cover; }
        .no-photo { color: #aaa; font-size: 0.8rem; }

        .wish-footer {
            padding: 12px 15px; border-top: 1px solid #eee; background-color: #fff;
            display: flex; justify-content: space-between; align-items: center;
        }
        
        .date-text { font-size: 0.75rem; color: #999; }
        .action-buttons { display: flex; gap: 5px; }

        .btn-icon {
            width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            border: none; outline: none; transition: all 0.2s; cursor: pointer;
        }
        
        /* Individual Action Colors */
        .btn-view { color: #17a2b8; background: rgba(23, 162, 184, 0.1); }
        .btn-view:hover { background: #17a2b8; color: white; }
        
        .btn-restore { color: #28a745; background: rgba(40, 167, 69, 0.1); }
        .btn-restore:hover { background: #28a745; color: white; }
        
        .btn-delete { color: #dc3545; background: rgba(220, 53, 69, 0.1); }
        .btn-delete:hover { background: #dc3545; color: white; }

        /* Modals */
        .modal-header-restore { background-color: #28a745; color: white; }
        .modal-header-delete { background-color: #dc3545; color: white; }

        /* Email Preview */
        .email-container { width: 100%; max-width: 600px; margin: 0 auto; background: #fff; border: 1px solid #e0e0e0; color: #333; }
        .email-header { background: #f8f9fa; padding: 15px; text-align: center; border-bottom: 3px solid #dc3545; }
        .email-body { padding: 25px; }
        .email-photo img { max-width: 100%; margin: 15px auto; display: block; }
    </style>
</head>
<body>
    
    <div id="loader">
        <div class="spinner-container">
            <div class="spinner"></div>
            <img src="BWU_logo.png" alt="Loading..." class="loader-logo">
        </div>
    </div>

    <div class="container wrapper">
        <div class="page-header">
            <h2><i class="fas fa-trash-alt mr-2"></i>Trash Bin (Wishes)</h2>
            <a href="wishes.php" class="btn btn-back"><i class="fas fa-arrow-left mr-1"></i> Library</a>
        </div>

        <?php if(isset($_SESSION['trash_msg'])): ?>
            <div class="alert alert-<?php echo $_SESSION['trash_msg_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['trash_msg']; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <?php unset($_SESSION['trash_msg']); unset($_SESSION['trash_msg_type']); ?>
        <?php endif; ?>

        <form action="trash_wishes_action.php" method="POST" id="trashForm">
            <input type="hidden" name="action" id="formAction" value="">
            <input type="hidden" name="single_id" id="singleActionId" value="">

            <div class="bulk-actions">
                <div class="custom-control custom-checkbox mr-3">
                    <input type="checkbox" class="custom-control-input" id="selectAll">
                    <label class="custom-control-label font-weight-bold" for="selectAll">Select All</label>
                </div>
                <div class="border-left pl-3 ml-1 d-flex gap-2">
                    <button type="button" class="btn btn-bulk-restore mr-2" onclick="triggerBulkRestore()">
                        <i class="fas fa-trash-restore mr-1"></i> Restore Selected
                    </button>
                    <button type="button" class="btn btn-bulk-delete" onclick="triggerBulkDelete()">
                        <i class="fas fa-times-circle mr-1"></i> Delete Forever
                    </button>
                </div>
            </div>

            <div class="row">
                <?php
                $sql = "SELECT * FROM wishes WHERE is_deleted = 1 ORDER BY id DESC";
                if ($result = mysqli_query($link, $sql)) {
                    if (mysqli_num_rows($result) > 0) {
                        while ($row = mysqli_fetch_array($result)) {
                            $date = date("M j, Y", strtotime($row['created_at']));
                            
                            echo '<div class="col-lg-4 col-md-6 mb-4">';
                                echo '<div class="wish-card" id="card-'.$row['id'].'">';
                                    
                                    // Header with Checkbox
                                    echo '<div class="wish-header">';
                                        echo '<input type="checkbox" name="ids[]" value="'.$row['id'].'" class="card-checkbox wish-checkbox">';
                                        echo '<h5 title="'.htmlspecialchars($row['title']).'">';
                                            echo htmlspecialchars($row['title']);
                                        echo '</h5>';
                                    echo '</div>';

                                    // Body with Preview
                                    echo '<div class="wish-body">';
                                        echo '<div class="wish-content source-message">' . $row['wish_message'] . '</div>';
                                        
                                        
                                            
                                        
                                        echo '</div>';
                                    echo '</div>';

                                    // Footer with Actions
                                    echo '<div class="wish-footer">';
                                        echo '<span class="date-text">' . $date . '</span>';
                                        echo '<div class="action-buttons">';
                                            
                                            // Preview
                                            echo '<button type="button" class="btn-icon btn-view view-btn" 
                                                  data-title="'.htmlspecialchars($row['title']).'" 
                                                  data-id="'.$row['id'].'" 
                                                  title="Preview" data-toggle="tooltip"><i class="fas fa-eye"></i></button>';
                                            
                                            // Individual Restore
                                            echo '<button type="button" class="btn-icon btn-restore" 
                                                  onclick="triggerSingleRestore('.$row['id'].')" 
                                                  title="Restore" data-toggle="tooltip"><i class="fas fa-trash-restore"></i></button>';
                                            
                                            // Individual Delete
                                            echo '<button type="button" class="btn-icon btn-delete" 
                                                  onclick="triggerSingleDelete('.$row['id'].')" 
                                                  title="Delete Forever" data-toggle="tooltip"><i class="fas fa-times"></i></button>';
                                        
                                        echo '</div>';
                                    echo '</div>';

                                echo '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="col-12 text-center py-5 text-muted">';
                        echo '<i class="fas fa-trash fa-3x mb-3 text-secondary" style="opacity: 0.2"></i>';
                        echo '<h4>Trash Bin Empty</h4><p>No deleted wishes found.</p>';
                        echo '</div>';
                    }
                }
                mysqli_close($link);
                ?>
            </div>
        </form>
    </div>

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-body p-0">
                    <div class="email-container">
                        <div class="email-header"><h3 id="modal-title"></h3></div>
                        <div class="email-body">
                            <p class="text-muted">Preview of deleted wish:</p>
                            <div id="modal-message"></div>
                            <div id="modal-photo" class="email-photo" style="display:none;"></div>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="restoreModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-restore">
                    <h5 class="modal-title"><i class="fas fa-trash-restore mr-2"></i>Confirm Restore</h5>
                </div>
                <div class="modal-body">
                    <p class="lead mb-0">Restore <strong id="restoreCount">0</strong> wish(es)?</p>
                    <small class="text-muted">They will be moved back to the main library.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitForm('restore')">Yes, Restore</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deletePermModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-delete">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Delete Forever</h5>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-times-circle fa-4x text-danger mb-3 opacity-50"></i>
                    <p class="font-weight-bold text-danger">This action cannot be undone!</p>
                    <p>You are about to permanently delete <strong id="deleteCount">0</strong> wish(es).</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="submitForm('delete_permanent')">Delete Forever</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Loader
        window.addEventListener('load', function() { setTimeout(function() { document.getElementById('loader').style.display = 'none'; }, 400); });
        
        // Tooltips
        $(function () { $('[data-toggle="tooltip"]').tooltip() });

        // Select All Logic
        $('#selectAll').change(function() {
            $('.wish-checkbox').prop('checked', $(this).prop('checked'));
            toggleCardStyles();
        });

        $('.wish-checkbox').change(function() {
            toggleCardStyles();
        });

        function toggleCardStyles() {
            $('.wish-checkbox').each(function() {
                var card = $(this).closest('.wish-card');
                if($(this).is(':checked')) card.addClass('selected');
                else card.removeClass('selected');
            });
        }

        // Preview Logic
        $('.view-btn').click(function() {
            var card = $('#card-' + $(this).data('id'));
            $('#modal-title').text($(this).data('title'));
            $('#modal-message').html(card.find('.source-message').html());
            
            var photoDiv = $('#modal-photo').empty().hide();
            var img = card.find('.source-photo img');
            if(img.length > 0) {
                photoDiv.append(img.clone()).show();
            }
            $('#previewModal').modal('show');
        });

        // --- Action Logic ---
        
        // Helper to reset inputs
        function resetActionInputs() {
            $('#singleActionId').val(''); // Clear single ID
            // Don't clear checkboxes, we might need them
        }

        // 1. Bulk Triggers
        function triggerBulkRestore() {
            resetActionInputs();
            var c = $('.wish-checkbox:checked').length;
            if(c === 0) { alert("Please select at least one wish."); return; }
            $('#restoreCount').text(c);
            $('#restoreModal').modal('show');
        }

        function triggerBulkDelete() {
            resetActionInputs();
            var c = $('.wish-checkbox:checked').length;
            if(c === 0) { alert("Please select at least one wish."); return; }
            $('#deleteCount').text(c);
            $('#deletePermModal').modal('show');
        }

        // 2. Single Triggers
        function triggerSingleRestore(id) {
            // Uncheck all boxes to avoid confusion, or just set single ID priority in PHP
            // Strategy: Clear checkboxes UI for clarity
            $('.wish-checkbox').prop('checked', false); toggleCardStyles();
            
            // Check the specific hidden one, OR just use hidden input
            // We'll use a hidden input "single_id" to simplify.
            // But the form submits "ids[]". Let's hack it: check the specific box programmatically.
            $('input[value="'+id+'"]').prop('checked', true);
            toggleCardStyles();

            $('#restoreCount').text(1);
            $('#restoreModal').modal('show');
        }

        function triggerSingleDelete(id) {
            $('.wish-checkbox').prop('checked', false); toggleCardStyles();
            $('input[value="'+id+'"]').prop('checked', true);
            toggleCardStyles();
            
            $('#deleteCount').text(1);
            $('#deletePermModal').modal('show');
        }

        // 3. Submit
        function submitForm(actionName) {
            $('#formAction').val(actionName);
            $('#trashForm').submit();
        }
    </script>
</body>
</html>
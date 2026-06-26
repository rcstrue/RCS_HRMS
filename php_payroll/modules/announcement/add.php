<?php
$pageTitle = 'Add Announcement';
$annData = null;
$isEdit = false;

if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $annData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($annData) { $pageTitle = 'Edit Announcement'; $isEdit = true; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'title' => sanitize($_POST['title']),
        'content' => sanitize($_POST['content']),
        'type' => sanitize($_POST['type'] ?? 'general'),
        'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
        'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'is_pinned' => isset($_POST['is_pinned']) ? 1 : 0,
    ];
    
    try {
        if ($isEdit) {
            $stmt = $db->prepare("UPDATE announcements SET title=?, content=?, type=?, start_date=?, end_date=?, is_active=?, is_pinned=? WHERE id=?");
            $stmt->execute([$data['title'], $data['content'], $data['type'], $data['start_date'], $data['end_date'], $data['is_active'], $data['is_pinned'], $annData['id']]);
            setFlash('success', 'Updated!');
        } else {
            $stmt = $db->prepare("INSERT INTO announcements (title, content, type, start_date, end_date, is_active, is_pinned) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$data['title'], $data['content'], $data['type'], $data['start_date'], $data['end_date'], $data['is_active'], $data['is_pinned']]);
            setFlash('success', 'Created!');
        }
        redirect('index.php?page=announcement/list');
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
}

$types = ['general'=>'General','holiday'=>'Holiday','policy'=>'Policy','event'=>'Event','urgent'=>'Urgent'];
?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-megaphone me-2"></i><?php echo $isEdit ? 'Edit' : 'New'; ?> Announcement</h5></div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Title</label>
                            <input type="text" class="form-control" name="title" required value="<?php echo sanitize($annData['title'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="type">
                                <?php foreach ($types as $v=>$l): ?><option value="<?php echo $v; ?>" <?php echo ($annData['type']??'general')==$v?'selected':''; ?>><?php echo $l; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Content</label>
                            <textarea class="form-control" name="content" rows="6" required><?php echo sanitize($annData['content'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $annData['start_date'] ?? date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $annData['end_date'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-3">
                                <input type="checkbox" class="form-check-input" name="is_active" id="is_active" <?php echo !empty($annData['is_active']) || !$isEdit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-3">
                                <input type="checkbox" class="form-check-input" name="is_pinned" id="is_pinned" <?php echo !empty($annData['is_pinned']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_pinned">Pin to Top</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="index.php?page=announcement/list" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update' : 'Create'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

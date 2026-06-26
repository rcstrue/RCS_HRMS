<?php $pageTitle = 'Announcements';
$db->exec("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    type ENUM('general','holiday','policy','event','urgent') DEFAULT 'general',
    start_date DATE,
    end_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$announcements = $db->query("SELECT * FROM announcements ORDER BY is_pinned DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$typeColors = ['general'=>'primary','holiday'=>'success','policy'=>'info','event'=>'warning','urgent'=>'danger'];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-megaphone me-2"></i>Announcements</h4>
            <a href="index.php?page=announcement/add" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New</a>
        </div>
        
        <div class="row">
            <?php if (empty($announcements)): ?>
            <div class="col-12">
                <div class="card"><div class="card-body text-center py-5">
                    <i class="bi bi-megaphone fs-1 text-muted d-block mb-3"></i>
                    <p class="text-muted">No announcements yet</p>
                    <a href="index.php?page=announcement/add" class="btn btn-primary">Create First Announcement</a>
                </div></div>
            </div>
            <?php else: ?>
            <?php foreach ($announcements as $a): ?>
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between">
                        <span class="badge bg-<?php echo $typeColors[$a['type']] ?? 'secondary'; ?>"><?php echo ucfirst($a['type']); ?></span>
                        <?php if ($a['is_pinned']): ?><i class="bi bi-pin-fill text-warning"></i><?php endif; ?>
                    </div>
                    <div class="card-body">
                        <h5 class="card-title"><?php echo sanitize($a['title']); ?></h5>
                        <p class="card-text small text-muted"><?php echo sanitize(substr($a['content'] ?? '', 0, 100)); ?>...</p>
                    </div>
                    <div class="card-footer bg-transparent">
                        <small class="text-muted"><?php echo formatDate($a['created_at']); ?></small>
                        <a href="index.php?page=announcement/add&id=<?php echo $a['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

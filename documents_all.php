<?php
// documents_all.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDB();

$page_title = 'หนังสือราชการทั้งหมด';
require_once 'includes/header.php';

// Pagination setup
$limit = 10; // Number of documents per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search parameters
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Build the base query
$sql = "SELECT
            d.id,
            d.document_number,
            d.title,
            d.description,
            dt.name AS document_type_name,
            s.first_name AS sender_first_name,
            s.last_name AS sender_last_name,
            d.priority,
            d.due_date,
            d.file_path,
            d.original_filename,
            d.status,
            d.created_at
        FROM
            documents d
        LEFT JOIN
            document_types dt ON d.document_type_id = dt.id
        LEFT JOIN
            users s ON d.sender_id = s.id
        WHERE 1=1"; // Start with a true condition for easy appending

$params = [];
$types = "";

// Add search condition
if (!empty($search_query)) {
    $sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.description LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR dt.name LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= "ssssss";
}

$sql .= " ORDER BY d.created_at DESC LIMIT ?, ?";
$params = array_merge($params, [$offset, $limit]);
$types .= "ii";

$stmt = $db->prepare($sql);

if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($db->error));
}

// Dynamically bind parameters
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$documents = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total number of records for pagination
$total_sql = "SELECT COUNT(d.id) AS total_count
              FROM documents d
              LEFT JOIN document_types dt ON d.document_type_id = dt.id
              LEFT JOIN users s ON d.sender_id = s.id
              WHERE 1=1";

$total_params = [];
$total_types = "";

if (!empty($search_query)) {
    $total_sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.description LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR dt.name LIKE ?)";
    $total_search_param = '%' . $search_query . '%';
    $total_params = array_merge($total_params, [$total_search_param, $total_search_param, $total_search_param, $total_search_param, $total_search_param, $total_search_param]);
    $total_types .= "ssssss";
}

$total_stmt = $db->prepare($total_sql);
if ($total_stmt === false) {
    die('Total prepare failed: ' . htmlspecialchars($db->error));
}

if (!empty($total_params)) {
    $total_stmt->bind_param($total_types, ...$total_params);
}

$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_row = $total_result->fetch_assoc();
$total_documents = $total_row['total_count'];
$total_stmt->close();

$total_pages = ceil($total_documents / $limit);

// Function to get recipients for a document
function getDocumentRecipients($document_id, $db)
{
    $recipients = [];
    $sql = "SELECT u.first_name, u.last_name
            FROM document_recipients dr
            JOIN users u ON dr.recipient_id = u.id
            WHERE dr.document_id = ?";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $document_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['first_name'] . ' ' . $row['last_name'];
        }
        $stmt->close();
    }
    return empty($recipients) ? 'ไม่ระบุผู้รับ' : implode(', ', $recipients);
}

?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4">
                <i class="fas fa-book me-2"></i><?php echo $page_title; ?>
            </h1>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-12">
            <div class="glassmorphism p-3 rounded-lg shadow-lg">
                <form action="documents_all.php" method="GET" class="row g-3 align-items-center">
                    <div class="col-md-8">
                        <input type="text" name="search" class="form-control glassmorphism-input" placeholder="ค้นหาด้วยเลขที่, หัวข้อ, คำอธิบาย, ผู้ส่ง, หรือประเภทเอกสาร..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-auto"> 
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-2"></i>ค้นหา</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="glassmorphism p-4 rounded-lg shadow-lg table-responsive">
                <?php if (!empty($documents)): ?>
                    <table class="table table-hover table-dark table-striped">
                        <thead>
                            <tr>
                                <th scope="col">เลขที่หนังสือ</th>
                                <th scope="col">หัวข้อ</th>
                                <th scope="col">ประเภท</th>
                                <th scope="col">ผู้ส่ง</th>
                                <th scope="col">ผู้รับ</th>
                                <th scope="col">วันที่ส่ง</th>
                                <th scope="col">สถานะ</th>
                                <th scope="col">ดาวน์โหลด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($doc['document_number']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['document_type_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($doc['sender_first_name'] . ' ' . $doc['sender_last_name']); ?></td>
                                    <td><?php echo getDocumentRecipients($doc['id'], $db); ?></td>
                                    <td><?php echo formatThaiDateTime($doc['created_at']); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch ($doc['status']) {
                                            case 'draft':
                                                $status_class = 'badge bg-secondary';
                                                break;
                                            case 'sent':
                                                $status_class = 'badge bg-info';
                                                break;
                                            case 'completed':
                                                $status_class = 'badge bg-success';
                                                break;
                                            default:
                                                $status_class = 'badge bg-light text-dark';
                                                break;
                                        }
                                        ?>
                                        <span class="<?php echo $status_class; ?>"><?php echo htmlspecialchars($doc['status']); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($doc['file_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" class="btn btn-sm btn-outline-info" download>
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link glassmorphism-pagination-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_query); ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link glassmorphism-pagination-link <?php echo ($i == $page) ? 'glassmorphism-pagination-active' : ''; ?>" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link glassmorphism-pagination-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_query); ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>

                <?php else: ?>
                    <div class="alert alert-info text-center" role="alert">
                        ไม่พบหนังสือราชการที่ตรงกับเงื่อนไขการค้นหา
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
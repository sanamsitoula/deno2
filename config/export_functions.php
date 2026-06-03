<?php 
function exportJobTickets($conn, $filters) {
    // Build query based on filters
    $query = "SELECT j.job_ticket_code, b.book_code, b.book_name, b.class_level,
                     fy.fiscal_code, j.lot, j.print_qty, j.status,
                     u.username as created_by, j.created_date
              FROM job_ticket j
              JOIN books b ON j.book_id = b.book_id
              JOIN users u ON j.created_by = u.id
              JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
              WHERE 1=1";
    
    $params = [];
    
    // Add filters to query
    foreach ($filters as $field => $value) {
        if (!empty($value) && $field != 'export') {
            switch ($field) {
                case 'job_ticket_code':
                    $query .= " AND j.job_ticket_code = :job_ticket_code";
                    $params[':job_ticket_code'] = $value;
                    break;
                // Add other filters similarly...
            }
        }
    }
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate Excel or PDF based on $filters['export']
    // Implementation depends on your preferred export library
}

?>
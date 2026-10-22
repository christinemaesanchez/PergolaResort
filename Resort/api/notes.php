<?php
require_once __DIR__ . '/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // -------------------------------------------------------------
    // GET: Fetch all notes for the authenticated user
    // -------------------------------------------------------------
    case 'GET':
        $stmt = $conn->prepare("SELECT id, title, content, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        http_response_code(200);
        echo json_encode($notes);
        exit();

    // -------------------------------------------------------------
    // POST: Create a new note
    // -------------------------------------------------------------
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty(trim($data['title'] ?? ''))) {
            http_response_code(400);
            echo json_encode(['error' => 'Title is required.']);
            exit();
        }

        $title = trim($data['title']);
        $content = trim($data['content'] ?? '');

        $stmt = $conn->prepare("INSERT INTO notes (user_id, title, content) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $_SESSION['user_id'], $title, $content);

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode([
                'id' => $stmt->insert_id,
                'title' => $title,
                'content' => $content,
                'message' => 'Note created successfully.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create note.']);
        }
        exit();

    // -------------------------------------------------------------
    // PUT: Update an existing note owned by the user
    // -------------------------------------------------------------
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);

        $id = $data['id'] ?? 0;
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');

        if (empty($id) || empty($title)) {
            http_response_code(400);
            echo json_encode(['error' => 'Note ID and title are required.']);
            exit();
        }

        $stmt = $conn->prepare("UPDATE notes SET title = ?, content = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ssii', $title, $content, $id, $_SESSION['user_id']);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Note not found or no changes made.']);
            exit();
        }

        http_response_code(200);
        echo json_encode(['message' => 'Note updated successfully.']);
        exit();

    // -------------------------------------------------------------
    // DELETE: Remove a note owned by the user
    // -------------------------------------------------------------
    case 'DELETE':
        $id = $_GET['id'] ?? 0;

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Note ID is required in URL query string (?id=X).']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM notes WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $id, $_SESSION['user_id']);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Note not found or unauthorized.']);
            exit();
        }

        http_response_code(200);
        echo json_encode(['message' => 'Note deleted successfully.']);
        exit();

    // -------------------------------------------------------------
    // Default: Handle unsupported HTTP methods
    // -------------------------------------------------------------
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit();
}
<?php
// app/Controllers/AdminController.php
//
// Admin Module
// Step 1. Opens the administration dashboard.                -> dashboard()
// Step 2. Selects a user account to manage.                  -> (frontend selection; loads step 3)
// Step 3. Reviews the user's account information.             -> getUser()
// Step 4. Updates, disables, or deletes the selected account. -> updateUser() / setStatus() / deleteUser()
// Step 5. Confirms the administrative action.                 -> (each action below returns a confirmation)
// Step 6. Returns to the user management page.                -> (frontend re-loads dashboard())

class AdminController {
    private $db;
    private $userModel;

    public function __construct() {
        Security::requireAdmin();
        $this->db = Database::getInstance();
        $this->userModel = new User();
    }

    // Action 1: Displays the administration dashboard and the list of registered users.
    public function dashboard($input) {
        $users = $this->userModel->getAllUsers();
        return ['success' => true, 'data' => $users];
    }

    // Action 2: Retrieves and displays the selected user's account details.
    public function getUser($input) {
        $id = (int)($input['id'] ?? 0);
        if (!$id) return ['error' => 'Invalid user'];

        $user = $this->userModel->getAccountDetails($id);
        if (!$user) return ['error' => 'User not found'];

        return ['success' => true, 'data' => $user];
    }

    // Action 3-4: Allows the administrator to modify the user's account information or status,
    // applies the requested changes, and updates the database.
    public function updateUser($input) {
        $id = (int)($input['id'] ?? 0);
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? '';
        $status = $input['status'] ?? '';

        if (!$id) return ['error' => 'Invalid user'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'Invalid email format'];
        if (!in_array($role, ['User', 'Admin'], true)) return ['error' => 'Invalid role'];
        if (!in_array($status, ['Active', 'Disabled'], true)) return ['error' => 'Invalid status'];

        $existing = $this->userModel->findById($id);
        if (!$existing) return ['error' => 'User not found'];

        // Guard against locking the system out of every admin account
        if ($existing['role'] === 'Admin' && $role !== 'Admin' && $this->userModel->countAdmins() <= 1) {
            return ['error' => 'Cannot remove the last remaining administrator'];
        }

        $ok = $this->userModel->updateAccount($id, $email, $role, $status);
        if ($ok === false) return ['error' => 'That email is already in use by another account'];

        // Step 5: Displays a confirmation
        return ['success' => true, 'message' => 'Account updated successfully', 'data' => $this->userModel->getAccountDetails($id)];
    }

    // Action 3-4: Disable/re-enable the selected user's account (status-only change).
    public function setStatus($input) {
        $id = (int)($input['id'] ?? 0);
        $status = $input['status'] ?? '';

        if (!$id) return ['error' => 'Invalid user'];
        if (!in_array($status, ['Active', 'Disabled'], true)) return ['error' => 'Invalid status'];

        $existing = $this->userModel->findById($id);
        if (!$existing) return ['error' => 'User not found'];

        if ($existing['role'] === 'Admin' && $status === 'Disabled' && $this->userModel->countAdmins() <= 1) {
            return ['error' => 'Cannot disable the last remaining administrator'];
        }

        $this->userModel->setStatus($id, $status);

        $verb = $status === 'Disabled' ? 'disabled' : 're-enabled';
        return ['success' => true, 'message' => "Account $verb successfully", 'data' => $this->userModel->getAccountDetails($id)];
    }

    // Action 3-4: Deletes the selected user account and updates the database (cascade).
    public function deleteUser($input) {
        $id = (int)($input['id'] ?? 0);
        if (!$id) return ['error' => 'Invalid user'];

        $existing = $this->userModel->findById($id);
        if (!$existing) return ['error' => 'User not found'];

        if ((int)$_SESSION['user_id'] === $id) {
            return ['error' => 'You cannot delete the account you are currently signed in with'];
        }
        if ($existing['role'] === 'Admin' && $this->userModel->countAdmins() <= 1) {
            return ['error' => 'Cannot delete the last remaining administrator'];
        }

        $this->userModel->deleteAccount($id);

        // Step 5: Displays a confirmation
        return ['success' => true, 'message' => 'Account deleted successfully'];
    }
}
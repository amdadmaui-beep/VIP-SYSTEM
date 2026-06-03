<?php
$profile_role_name = '';
if (isset($_SESSION['user_role'])) {
    $roleId = (int) $_SESSION['user_role'];
    if ($roleId === 1) $profile_role_name = 'Owner';
    elseif ($roleId === 2) $profile_role_name = 'Manager';
    elseif (in_array($roleId, [3, 6])) $profile_role_name = 'Cashier';
    elseif ($roleId === 4) $profile_role_name = 'Rider';
    elseif ($roleId === 5) $profile_role_name = 'Inventory Staff';
    else $profile_role_name = 'Staff';
}
?>
<div class="modal" id="profileModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2><i class="fas fa-user-circle"></i> User Profile</h2>
            <button class="modal-close" onclick="closeModal('profileModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 2rem;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #7c3aed); color: white; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; margin: 0 auto 1rem;">
                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
            </div>
            <h3 style="margin: 0.5rem 0 0.25rem; font-size: 1.25rem;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?></h3>
            <p style="color: #64748b; margin: 0 0 0.25rem;">@<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></p>
            <p style="color: #6366f1; font-weight: 600; margin: 0;"><?php echo $profile_role_name; ?></p>
            <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid #e2e8f0;">
            <a href="../logout.php" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: #ef4444; color: white; border-radius: 10px; text-decoration: none; font-weight: 600;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</div>

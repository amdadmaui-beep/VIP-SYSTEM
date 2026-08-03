<?php
/**
 * Shared helpers for saving damage evidence photos.
 */

if (!function_exists('saveDamagePhotoFile')) {
    /**
     * Save one uploaded image to uploads/damage_reports/.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int} $file Single $_FILES entry
     * @param string $filenamePrefix e.g. dgr_12_
     */
    function saveDamagePhotoFile(array $file, string $filenamePrefix): string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid photo upload.');
        }
        if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Photo upload failed.');
        }
        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            throw new RuntimeException('Photo must be 4MB or smaller.');
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = null;
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = finfo_file($fi, $file['tmp_name']);
                finfo_close($fi);
            }
        }
        if (!$mime || !isset($allowed[$mime])) {
            throw new RuntimeException('Photo must be JPEG, PNG, or WebP.');
        }

        $dir = __DIR__ . '/../uploads/damage_reports';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create upload directory.');
            }
        }

        $ext = $allowed[$mime];
        $name = $filenamePrefix . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Failed to save photo.');
        }

        return 'uploads/damage_reports/' . $name;
    }
}

if (!function_exists('saveSingleDamagePhotoFromRequest')) {
    /** @return string|null Relative path under capstone/ */
    function saveSingleDamagePhotoFromRequest(string $inputName, string $filenamePrefix): ?string
    {
        if (empty($_FILES[$inputName]) || empty($_FILES[$inputName]['name'])) {
            return null;
        }
        return saveDamagePhotoFile($_FILES[$inputName], $filenamePrefix);
    }
}

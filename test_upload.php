<?php

// Test d'upload simple pour diagnostiquer le problème
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_file'])) {
    $file = $_FILES['test_file'];
    
    // Vérifier si le fichier a été uploadé
    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = storage_path('app/public/ambassadors/');
        
        // Créer le dossier si nécessaire
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'test_' . time() . '_' . $file['name'];
        $filepath = $uploadDir . $filename;
        
        // Tenter de déplacer le fichier
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            echo json_encode([
                'success' => true,
                'message' => 'Fichier uploadé avec succès',
                'filename' => $filename,
                'filepath' => $filepath,
                'url' => url('/api/storage/ambassadors/' . $filename)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors du déplacement du fichier',
                'error' => error_get_last(),
                'tmp_name' => $file['tmp_name'],
                'destination' => $filepath
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur upload',
            'error_code' => $file['error'],
            'error_message' => $file['error'] === UPLOAD_ERR_INI_SIZE ? 'Fichier trop gros' : 'Autre erreur'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Méthode non autorisée ou fichier non fourni'
    ]);
}

?>

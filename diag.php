<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnóstico</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f0f0f0; }
        .ok { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        pre { background: white; padding: 10px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h1>Diagnóstico del Sistema</h1>
    
    <h2>1. PHP Info</h2>
    <div class="info">
        PHP Version: <?php echo phpversion(); ?><br>
        Server Software: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?><br>
        Document Root: <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'; ?><br>
        Script Filename: <?php echo __FILE__; ?><br>
        Current Directory: <?php echo __DIR__; ?><br>
    </div>

    <h2>2. Archivos en directorio actual</h2>
    <pre><?php
    $files = scandir(__DIR__);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = __DIR__ . '/' . $file;
        $size = is_file($path) ? filesize($path) : 'DIR';
        echo "$file ($size bytes)\n";
    }
    ?></pre>

    <h2>3. Verificar archivos críticos</h2>
    <?php
    $critical = ['game.php', 'db.php', 'index.html'];
    foreach ($critical as $file) {
        $exists = file_exists(__DIR__ . '/' . $file);
        $class = $exists ? 'ok' : 'error';
        $status = $exists ? '✓ EXISTS' : '✗ MISSING';
        echo "<div class='$class'>$file: $status</div>";
    }
    ?>

    <h2>4. Test de base de datos</h2>
    <?php
    try {
        if (file_exists(__DIR__ . '/db.php')) {
            require __DIR__ . '/db.php';
            $db = getDb();
            echo "<div class='ok'>✓ Base de datos conectada correctamente</div>";
            
            // Probar crear una tabla
            $db->exec("SELECT 1");
            echo "<div class='ok'>✓ Query de prueba ejecutado</div>";
        } else {
            echo "<div class='error'>✗ db.php no encontrado</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>✗ Error DB: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>

    <h2>5. Test de game.php</h2>
    <?php
    if (file_exists(__DIR__ . '/game.php')) {
        echo "<div class='ok'>✓ game.php existe</div>";
        echo "<div class='info'>Probando endpoint...</div>";
        
        // Simular request
        $_GET['action'] = 'join';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        ob_start();
        try {
            include __DIR__ . '/game.php';
            $output = ob_get_clean();
            echo "<div class='ok'>✓ game.php responde</div>";
            echo "<pre>Response: " . htmlspecialchars(substr($output, 0, 200)) . "</pre>";
        } catch (Exception $e) {
            ob_end_clean();
            echo "<div class='error'>✗ Error en game.php: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div class='error'>✗ game.php no encontrado</div>";
    }
    ?>

    <h2>6. Permisos de escritura</h2>
    <?php
    $testDirs = [
        getenv('HOME') . '/site/data',
        getenv('HOME') . '/LogFiles',
        __DIR__
    ];
    
    foreach ($testDirs as $dir) {
        if (!$dir) continue;
        $exists = is_dir($dir);
        $writable = $exists && is_writable($dir);
        
        if ($writable) {
            echo "<div class='ok'>✓ $dir (writable)</div>";
        } elseif ($exists) {
            echo "<div class='error'>✗ $dir (not writable)</div>";
        } else {
            echo "<div class='info'>- $dir (doesn't exist)</div>";
        }
    }
    ?>

    <h2>7. Variables de entorno Azure</h2>
    <pre><?php
    $azureVars = ['HOME', 'WEBSITE_SITE_NAME', 'WEBSITE_HOSTNAME'];
    foreach ($azureVars as $var) {
        $value = getenv($var) ?: 'Not set';
        echo "$var = $value\n";
    }
    ?></pre>
</body>
</html>
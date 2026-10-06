<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>DPLChk2 - Dienst-Status</title>

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                         Roboto, sans-serif;
            background: #f4f6f9;
            color: #333;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
        }

        h1 {
            color: #2c3e50;
            margin-top: 0;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 12px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
        }

        .service {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 5px;
            border-bottom: 1px solid #eee;
        }

        .service:last-child {
            border-bottom: none;
        }

        .service-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .icon {
            font-size: 1.3em;
        }

        .badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        .online {
            background: #d4edda;
            color: #155724;
        }

        .online .dot {
            background: #28a745;
        }

        .offline {
            background: #f8d7da;
            color: #721c24;
        }

        .offline .dot {
            background: #dc3545;
        }

        .details {
            font-size: 0.8em;
            color: #888;
            margin-top: 4px;
        }

        .footer {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            color: #888;
            font-size: 0.85em;
            text-align: right;
        }

        .footer a {
            color: #2c3e50;
        }
    </style>
</head>

<body>

<div class="card">

    <h1>DPLChk2 Dienst-Status</h1>

    <div class="subtitle">
        Aktuelle Erreichbarkeit der Docker-Container:
    </div>


    <!-- ========================================================= -->
    <!-- NGINX / PHP -->
    <!-- ========================================================= -->

    <div class="service">

        <div class="service-name">
            <span class="icon">🌐</span>

            <div>
                <strong>Nginx Webserver & PHP Backend</strong>
                <div class="details">
                    Weboberfläche
                </div>
            </div>
        </div>

        <div class="badge online">
            <span class="dot"></span>
            Online
        </div>

    </div>


    <!-- ========================================================= -->
    <!-- MYSQL -->
    <!-- ========================================================= -->

    <?php

    $mysql_host = 'mysql_database';
    $mysql_port = 3306;

    $mysql_connection = @fsockopen(
        $mysql_host,
        $mysql_port,
        $mysql_errno,
        $mysql_errstr,
        2
    );

    $mysql_online = is_resource($mysql_connection);

    if ($mysql_online) {
        fclose($mysql_connection);
    }

    ?>

    <div class="service">

        <div class="service-name">
            <span class="icon">🗄️</span>

            <div>
                <strong>MySQL Datenbank</strong>

                <div class="details">
                    mysql_database : 3306
                </div>
            </div>
        </div>

        <?php if ($mysql_online): ?>

            <div class="badge online">
                <span class="dot"></span>
                Online
            </div>

        <?php else: ?>

            <div class="badge offline">
                <span class="dot"></span>
                Offline
            </div>

        <?php endif; ?>

    </div>


    <!-- ========================================================= -->
    <!-- PYTHON -->
    <!-- ========================================================= -->

    <?php

    $python_bin = getenv('PYTHON_BIN');
    if (!is_string($python_bin) || $python_bin === '') {
        $python_bin = 'python3';
    }

    $python_online = false;
    $python_version = '';
    $python_descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $python_process = @proc_open(
        [$python_bin, '--version'],
        $python_descriptors,
        $python_pipes
    );

    if (is_resource($python_process)) {
        $python_stdout = stream_get_contents($python_pipes[1]);
        $python_stderr = stream_get_contents($python_pipes[2]);
        fclose($python_pipes[1]);
        fclose($python_pipes[2]);
        $python_code = proc_close($python_process);
        $python_version = trim((string) $python_stdout . ' ' . (string) $python_stderr);
        $python_online = $python_code === 0 && $python_version !== '';
    }

    ?>

    <div class="service">

        <div class="service-name">
            <span class="icon">🐍</span>

            <div>
                <strong>Python</strong>

                <div class="details">
                    im PHP-Container<?= $python_version !== '' ? ' · ' . htmlspecialchars($python_version, ENT_QUOTES, 'UTF-8') : '' ?>
                </div>
            </div>
        </div>

        <?php if ($python_online): ?>

            <div class="badge online">
                <span class="dot"></span>
                Online
            </div>

        <?php else: ?>

            <div class="badge offline">
                <span class="dot"></span>
                Offline
            </div>

        <?php endif; ?>

    </div>


    <!-- ========================================================= -->
    <!-- ZEITSTEMPEL -->
    <!-- ========================================================= -->

    <div class="footer">

        <a href="index.php">Zum Dienstplan-Prüfer</a>
        <br>
        Letzte Prüfung:
        <?php
        date_default_timezone_set('Europe/Berlin');
        echo date('d.m.Y H:i:s');
        ?>

    </div>

</div>

</body>
</html>

<?php
/**
 * config/cloud.php
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES (in plain words):
 *   Our project now talks to TWO databases:
 *
 *     $pdo    -> MariaDB on XAMPP   (users, enrollment, billing, audit logs)
 *     $cloud  -> Supabase PostgreSQL (ONLY the website content: news,
 *                                     announcements, events)
 *
 *   This file opens the connection to the second one ($cloud).
 *   Every CMS PHP file that needs the cloud database does:
 *       require __DIR__ . '/../../config/cloud.php';
 *
 * SECURITY RULES:
 *   1. The password lives ONLY in this PHP file. PHP runs on the server, so
 *      visitors can never see it. NEVER copy it into a .js or .html file.
 *   2. NEVER upload this file to GitHub or share it in screenshots.
 *
 * BEFORE THIS WORKS (one-time setup on your laptop):
 *   Open C:\xampp\php\php.ini, find these two lines, and remove the ";"
 *   in front of them (the ";" means "ignored"):
 *        extension=pdo_pgsql
 *        extension=pgsql
 *   Then restart Apache in the XAMPP Control Panel.
 *
 * WHERE DO I GET THE VALUES BELOW?
 *   Supabase dashboard -> click the green "Connect" button at the top ->
 *   choose "Session pooler" -> copy host, port, user. The password is the one
 *   you typed when you created the project.
 *
 *   Why "Session pooler" and not "Direct connection"? The direct address only
 *   works on IPv6 internet, and many home connections in the Philippines are
 *   IPv4 only. The pooler address works on both.
 */

// --- 1. YOUR CONNECTION DETAILS (replace the CAPITAL words) -----------
$cloudHost = 'aws-0-ap-southeast-1.pooler.supabase.com';   // from the Connect button
$cloudPort = '5432';                                    // session pooler port
$cloudDb   = 'postgres';                                // Supabase always uses "postgres"
$cloudUser = 'postgres.lqgygcbpbacjxboxxwau';               // note the dot + project ref
$cloudPass = 'Grp73EINSCMS';                  // the password you saved

// --- 2. OPEN THE CONNECTION --------------------------------------------
try {
    // "pgsql:" tells PDO we are talking to PostgreSQL (MariaDB would be "mysql:").
    // sslmode=require means "encrypt the connection" (Supabase needs this).
    $cloud = new PDO(
        "pgsql:host=$cloudHost;port=$cloudPort;dbname=$cloudDb;sslmode=require",
        $cloudUser,
        $cloudPass,
        [
            // If a query fails, throw an error we can catch (instead of failing silently)
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Give us each row as ['title' => '...', 'body' => '...']
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // false = let the database itself do the prepared statements (safer)
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Show times in Philippine time. Without this, Supabase answers in UTC
    // (8 hours behind) and dates could look one day off.
    $cloud->exec("SET TIME ZONE 'Asia/Manila'");

} catch (PDOException $e) {
    // The REAL reason goes to the server's error log (C:\xampp\apache\logs\error.log).
    error_log('config/cloud.php: ' . $e->getMessage());
    // The visitor only sees a friendly message (never passwords or hostnames).
    // jsonError() comes from config/helpers.php, so load helpers.php BEFORE this file.
    jsonError(500, 'The website content database cannot be reached right now. Please try again.');
}

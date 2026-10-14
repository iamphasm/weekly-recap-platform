<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

// Not in any menu: reached only through the footer link.
page_header('About', !empty($_SESSION['teacher_id']) ? 'admin' : 'student');
?>
<h1>About Weekly Recap Platform</h1>
<div class="card">
  <p>Weekly Recap Platform is a small, self-hosted quiz system for schools. Teachers publish a short recap test each week;
    students sign in with their school email, answer the questions and see the correct answers once they have submitted.
    A debrief goes to the student by email after each test, and a monthly summary follows at the end of the month.</p>
  <ul>
    <li>Question types: multiple choice (one or several answers), true/false, short text and matching</li>
    <li>Optional time limit per test with a countdown, enforced on the server</li>
    <li>Students sign in with an emailed one-time code; the browser then stays signed in</li>
    <li>Teachers see results per test and a learning curve per student</li>
    <li>Your own logo, colors, background and site name</li>
  </ul>
  <p class="muted small">Built with PHP and MySQL. It runs on ordinary web hosting: unzip it into a folder and open it in a browser.</p>
</div>
<div class="card">
  <h2 style="margin-top:0">Version</h2>
  <p>Weekly Recap Platform <b><?= e(APP_VERSION) ?></b></p>
  <h2>Source code</h2>
  <p><a href="<?= e(APP_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= e(APP_REPO_URL) ?></a><br>
    <span class="muted small">New versions, the setup guide and bug reports are on GitHub.</span></p>
  <h2>Created by</h2>
  <p>Kristian Daae-Johansen / IamPhasm<br>
    <a href="https://github.com/iamphasm" target="_blank" rel="noopener noreferrer">github.com/iamphasm</a></p>
</div>
<p><a class="btn secondary" href="javascript:history.back()">Back</a></p>
<?php page_footer();

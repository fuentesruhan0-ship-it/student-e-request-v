<?php
/**
 * The old simulated gateway page has been retired.
 *
 * Real online payments now go through PayMongo's hosted checkout
 * (see includes/paymongo.php and student/payment.php). Manual payments are
 * uploaded as receipts in student/payment.php and verified by cashiers.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: ' . url('student/requests.php'));
exit;

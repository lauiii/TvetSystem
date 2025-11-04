<?php
require_once __DIR__ . '/../config.php';
requireRole('instructor');
// Reuse the existing assessments UI/logic; this separate entry point avoids confusion
include __DIR__ . '/assessments.php';

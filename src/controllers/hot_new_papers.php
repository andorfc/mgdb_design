<?php
/* file: controllers/hot_new_papers.php
 *
 * purpose: Top-level route interceptor for /hot_new_papers -> the modern
 *          Editorial Board reading list.
 *
 *          controller.php looks for ./controllers/<CONTROLLER>.php before it
 *          falls through to redirect.php, so this takes the route before the
 *          legacy shell is built.
 *
 *          Rollback: delete this file. controllers/community/hot_new_papers.php
 *          and its templates are untouched and answer the route again.
 */

include('./controllers/community/hot_new_papers_modern.php');

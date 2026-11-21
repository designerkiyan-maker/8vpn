<?php
/**
 * P6 unit — internal-error redaction contract (M-1): the client ALWAYS
 * receives the generic message and the CONTRACTUAL error code is preserved.
 */
require_once __DIR__ . '/../../../includes/core/class-db-error.php';

final class DbErrorTest extends P6UnitBase {

    public function testMessageIsGeneric() {
        $msg = \Eligius_Sub\Core\Class_DB_Error::message('unit.test', "Duplicate entry 'x' for key 'wp_eligius_licenses.uniq_user_active_plan'");
        $this->check('message is the generic client text', \Eligius_Sub\Core\Class_DB_Error::CLIENT_MESSAGE === $msg);
        $this->check('generic message contains no SQL fragment', false === strpos($msg, 'Duplicate') && false === strpos($msg, 'uniq_user_active_plan'));
    }

    public function testEmptyDbErrorStillGeneric() {
        $msg = \Eligius_Sub\Core\Class_DB_Error::message('unit.test', '');
        $this->check('empty raw error still yields generic client text', \Eligius_Sub\Core\Class_DB_Error::CLIENT_MESSAGE === $msg);
    }

    public function testLogWritesRawDetailServerSideOnly() {
        // Capture the PHP error log via a temp ini override (CLI-safe) and
        // verify the RAW detail goes to the log, not into the client message.
        $logFile = sys_get_temp_dir() . '/p6-unit-errorlog-' . getmypid() . '.log';
        $old = ini_set('error_log', $logFile);
        \Eligius_Sub\Core\Class_DB_Error::log('unit.logtest', 'SECRET_TABLE_NAME_INTERNAL');
        ini_set('error_log', $old);
        $logged = (string) file_get_contents($logFile);
        unlink($logFile);
        $this->check('raw detail IS in the server-side log', false !== strpos($logged, 'SECRET_TABLE_NAME_INTERNAL'));
        $this->check('log line carries the context tag', false !== strpos($logged, 'unit.logtest'));
        $this->check('log line carries the calling function (traceability)', false !== strpos($logged, 'testLogWritesRawDetailServerSideOnly'));
    }
}

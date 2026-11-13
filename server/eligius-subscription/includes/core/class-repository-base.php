<?php
/**
 * Base repository (doc 01 §2.1 core/class-repository-base.php).
 *
 * Common helpers shared by the concrete repositories. Every mutating helper
 * returns false on failure; callers inside transactions must additionally
 * check $wpdb->last_error explicitly (doc 02 §2.8.3 — wpdb does NOT throw).
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

abstract class Class_Repository_Base {

    /** Unprefixed table suffix, e.g. 'eligius_devices'. */
    abstract protected static function table_suffix();

    protected static function table() {
        global $wpdb;
        return $wpdb->prefix . static::table_suffix();
    }

    /** Fetch a single row by primary key. */
    public static function find($id) {
        global $wpdb;
        $table = static::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", (int) $id));
    }

    /** Insert a row; returns insert id or 0 on failure (check last_error). */
    public static function insert_row(array $data) {
        global $wpdb;
        $ok = $wpdb->insert(static::table(), $data);
        return (false === $ok) ? 0 : (int) $wpdb->insert_id;
    }

    /** Update rows by where clause; returns affected-row count or false. */
    public static function update_row(array $data, array $where) {
        global $wpdb;
        return $wpdb->update(static::table(), $data, $where);
    }

    /** True when the last wpdb statement recorded an error. */
    protected static function had_error() {
        global $wpdb;
        return ('' !== $wpdb->last_error);
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_posts( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 10;
    $status   = isset( $input['status'] )   ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'publish';
    if ( 'all' === $status ) $status = array( 'publish', 'draft', 'pending', 'future' );
    $q     = new WP_Query( array( 'post_status' => $status, 'posts_per_page' => $per_page, 'orderby' => 'date', 'order' => 'DESC' ) );
    $posts = array();
    foreach ( $q->posts as $p ) {
        $posts[] = array(
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'url'        => get_permalink( $p->ID ),
            'status'     => $p->post_status,
            'date'       => get_the_date( 'Y-m-d', $p->ID ),
            'author'     => get_the_author_meta( 'display_name', $p->post_author ),
            'categories' => wp_get_post_categories( $p->ID, array( 'fields' => 'names' ) ),
            'tags'       => wp_get_post_tags( $p->ID, array( 'fields' => 'names' ) ),
            'excerpt'    => has_excerpt( $p->ID ) ? get_the_excerpt( $p ) : wp_trim_words( $p->post_content, 40 ),
        );
    }
    return array( 'posts' => $posts, 'total' => $q->found_posts );
}

function wsp_execute_get_post( $input ) {
    $id   = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    $post = $id ? get_post( $id ) : null;
    if ( ! $post || 'post' !== $post->post_type ) return new WP_Error( 'not_found', 'Post not found.' );
    if ( ! current_user_can( 'read_post', $id ) ) return new WP_Error( 'forbidden', 'You do not have permission to read object ' . $id . '.' );
    return array(
        'id'         => $post->ID,
        'title'      => $post->post_title,
        'content'    => $post->post_content,
        'url'        => get_permalink( $post->ID ),
        'status'     => $post->post_status,
        'date'       => get_the_date( 'Y-m-d', $post->ID ),
        'author'     => get_the_author_meta( 'display_name', $post->post_author ),
        'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
        'tags'       => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
        'excerpt'    => has_excerpt( $post->ID ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 40 ),
    );
}

/**
 * Build post_date / post_date_gmt args from an optional ISO 8601 "date" input.
 * Returns WP_Error when status is "future" but no future date is available,
 * because WordPress would otherwise publish the post immediately.
 */
function wsp_mcp_post_date_args( $input, $status, $post = null ) {
    $args = array();
    $ts   = 0;
    if ( isset( $input['date'] ) && '' !== trim( (string) $input['date'] ) ) {
        $dt = date_create( sanitize_text_field( wp_unslash( $input['date'] ) ), wp_timezone() );
        if ( ! $dt ) {
            return new WP_Error( 'invalid_date', 'Could not parse "date". Use ISO 8601 in the site timezone, e.g. 2026-06-09T08:00:00.' );
        }
        $ts   = $dt->getTimestamp();
        $args = array(
            'post_date'     => $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
            'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
            'edit_date'     => true,
        );
    } elseif ( $post && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
        $ts = strtotime( $post->post_date_gmt . ' UTC' );
    }
    if ( 'future' === $status && $ts <= time() ) {
        return new WP_Error( 'invalid_date', 'Status "future" requires a "date" in the future; otherwise WordPress publishes the post immediately.' );
    }
    return $args;
}

function wsp_execute_create_post( $input ) {
    $args = array(
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => wp_kses_post( wp_unslash( $input['content'] ) ),
        'post_status'  => isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft',
        'post_type'    => 'post',
    );
    if ( ! empty( $input['excerpt'] ) )    $args['post_excerpt']  = sanitize_text_field( wp_unslash( $input['excerpt'] ) );
    if ( ! empty( $input['slug'] ) )       $args['post_name']     = sanitize_title( $input['slug'] );
    if ( ! empty( $input['categories'] ) ) $args['post_category'] = array_map( 'intval', $input['categories'] );
    $date_args = wsp_mcp_post_date_args( $input, $args['post_status'] );
    if ( is_wp_error( $date_args ) ) return array( 'success' => false, 'error' => $date_args->get_error_message() );
    $args = array_merge( $args, $date_args );
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    if ( ! empty( $input['tags'] ) ) wp_set_post_tags( $id, array_map( 'intval', $input['tags'] ) );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
}

function wsp_execute_update_post( $input ) {
    $post = wsp_mcp_guard_edit_post( isset( $input['id'] ) ? $input['id'] : 0, 'post' );
    if ( is_wp_error( $post ) ) return $post;
    if ( isset( $input['status'] ) ) {
        $status_check = wsp_mcp_guard_post_status( $post, $input['status'] );
        if ( is_wp_error( $status_check ) ) return $status_check;
    }
    $args = array( 'ID' => $post->ID );
    $target_status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : $post->post_status;
    if ( isset( $input['status'] ) || isset( $input['date'] ) ) {
        $date_args = wsp_mcp_post_date_args( $input, $target_status, $post );
        if ( is_wp_error( $date_args ) ) return $date_args;
        $args = array_merge( $args, $date_args );
    }
    if ( isset( $input['title'] ) )      $args['post_title']    = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['content'] ) )    $args['post_content']  = wp_kses_post( wp_unslash( $input['content'] ) );
    if ( isset( $input['status'] ) )     $args['post_status']   = sanitize_text_field( wp_unslash( $input['status'] ) );
    if ( isset( $input['categories'] ) ) $args['post_category'] = array_map( 'intval', $input['categories'] );
    $id = wp_update_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    if ( isset( $input['tags'] ) ) wp_set_post_tags( $id, array_map( 'intval', $input['tags'] ) );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
}

function wsp_execute_delete_post( $input ) {
    $post = wsp_mcp_guard_delete_post( isset( $input['id'] ) ? $input['id'] : 0, 'post' );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;
    return wp_trash_post( $id )
        ? array( 'success' => true,  'message' => "Post {$id} moved to trash." )
        : array( 'success' => false, 'error'   => 'Could not trash post.' );
}

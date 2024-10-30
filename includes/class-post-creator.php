<?php

class Post_Creator {
    public function create_posts($post_type, $data, $mapping, $object_path) {
        $result = array(
            'created' => 0,
            'updated' => 0,
            'errors' => array(),
        );
    
        $items = $this->get_items_from_path($data, $object_path);
        
        error_log("Number of items found: " . count($items));
        
        if (empty($items)) {
            $result['errors'][] = "No items found at object path: $object_path";
            return $result;
        }
    
        foreach ($items as $index => $item) {
            $post_data = array(
                'post_type' => $post_type,
                'post_status' => 'publish',
            );
    
            foreach ($mapping as $wp_field => $api_field) {
                $value = $this->get_nested_value($item, $api_field);
                error_log("Mapping $wp_field to $api_field. Value: " . print_r($value, true));
                
                if ($wp_field === 'post_title' || $wp_field === 'post_content' || $wp_field === 'post_excerpt') {
                    $post_data[$wp_field] = $value;
                } else {
                    $post_data['meta_input'][$wp_field] = $value;
                }
            }
    
            if (empty($post_data['post_title'])) {
                $result['errors'][] = "Item $index: Missing post title";
                error_log("Error: Missing post title for item $index");
                continue;
            }
    
            $existing_post = $this->find_existing_post($post_type, $post_data['post_title']);
            if ($existing_post) {
                $post_data['ID'] = $existing_post->ID;
                $post_id = wp_update_post($post_data);
                if (is_wp_error($post_id)) {
                    $result['errors'][] = "Item $index: Failed to update post - " . $post_id->get_error_message();
                    error_log("Error updating post: " . $post_id->get_error_message());
                } else {
                    $result['updated']++;
                    error_log("Updated post ID: $post_id");
                }
            } else {
                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) {
                    $result['errors'][] = "Item $index: Failed to create post - " . $post_id->get_error_message();
                    error_log("Error creating post: " . $post_id->get_error_message());
                } else {
                    $result['created']++;
                    error_log("Created new post ID: $post_id");
                }
            }
        }
    
        return $result;
    }
    
    private function get_nested_value($array, $path) {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (isset($array[$key])) {
                $array = $array[$key];
            } else {
                return null;
            }
        }
        return $array;
    }
    
    private function find_existing_post($post_type, $title) {
        $args = array(
            'post_type' => $post_type,
            'post_title' => $title,
            'post_status' => 'publish',
            'posts_per_page' => 1,
        );
        $posts = get_posts($args);
        
        if (!empty($posts)) {
            error_log("Found existing post with title: $title");
            return $posts[0];
        } else {
            error_log("No existing post found with title: $title");
            return null;
        }
    }

    private function get_items_from_path($data, $path) {
        if (empty($path)) {
            return is_array($data) ? $data : array($data);
        }
        
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $data = $data[$key];
            } else {
                return array();
            }
        }
        return is_array($data) ? $data : array($data);
    }
}
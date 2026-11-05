# NF API contract (v0)

Status: draft. WP 0.4 (ACF API inventory, A5) wrote the inventory below; WP 0.5 (A0) freezes it.

## ACF surface inventory (WP 0.4)

Draft by A5, to be frozen by A0 in WP 0.5. Source: Advanced Custom Fields 6.8.11 (free, wordpress.org), `includes/**`. ACF PRO-only and SCF-only additions are not covered yet (see Open questions in `reports/WP-0.4.md`). Counted: 585 non-underscore global functions, 183 filters, 65 actions under the `acf/` namespace. Functions beginning with `_` are ACF-private and are never implemented.

### Ranking

There is no theme corpus yet (WP 0.6 / A11 builds it), so ranking is by *role* and will be re-ranked by measured usage once the corpus exists.

- **P0, theme API.** Called from theme and plugin templates every day. Must exist in standalone mode with the same signature, return shape and `$post_id` handling. Differential-tested first.
- **P1, registration, data and structure API.** Used by plugins, themes registering fields in code, and integrations (Local JSON, field/group lookup, meta layer, REST, forms, terms). Implement with the P0 work or in the phase that owns the area.
- **P2, helpers.** `acf_*` utility functions that third-party code calls occasionally. Implement on demand: only when the corpus or the compat scanner shows a caller. Until then, the function is not defined.
- **P3, out of scope or admin-only.** Admin UI, upgrade routines, internal post-type (`acf-post-type`, `acf-taxonomy`, `acf-ui-options-page`) management, and flexible content (ADR-0008). Not implemented; the compat scanner reports callers.

### P0: implement (52 functions)

| Function | Source (ACF 6.8.11) |
| --- | --- |
| `acf_add_local_field` | `local-fields.php` |
| `acf_add_local_field_group` | `local-fields.php` |
| `acf_delete_metadata` | `acf-meta-functions.php` |
| `acf_form` | `forms/form-front.php` |
| `acf_form_head` | `forms/form-front.php` |
| `acf_format_value` | `acf-value-functions.php` |
| `acf_get_attachment` | `api/api-helpers.php` |
| `acf_get_field` | `acf-field-functions.php` |
| `acf_get_field_group` | `acf-field-group-functions.php` |
| `acf_get_field_groups` | `acf-field-group-functions.php` |
| `acf_get_field_type` | `fields.php` |
| `acf_get_fields` | `acf-field-functions.php` |
| `acf_get_image_sizes` | `api/api-helpers.php` |
| `acf_get_metadata` | `acf-meta-functions.php` |
| `acf_get_post_id_info` | `api/api-helpers.php` |
| `acf_get_post_templates` | `acf-post-functions.php` |
| `acf_get_setting` | `api/api-helpers.php` |
| `acf_get_valid_post_id` | `api/api-helpers.php` |
| `acf_get_value` | `acf-value-functions.php` |
| `acf_is_pro` | `acf-helper-functions.php` |
| `acf_register_field_type` | `fields.php` |
| `acf_shortcode` | `api/api-template.php` |
| `acf_update_metadata` | `acf-meta-functions.php` |
| `acf_update_setting` | `api/api-helpers.php` |
| `acf_update_value` | `acf-value-functions.php` |
| `add_row` | `api/api-template.php` |
| `add_sub_row` | `api/api-template.php` |
| `delete_field` | `api/api-template.php` |
| `delete_row` | `api/api-template.php` |
| `delete_sub_field` | `api/api-template.php` |
| `delete_sub_row` | `api/api-template.php` |
| `get_field` | `api/api-template.php` |
| `get_field_object` | `api/api-template.php` |
| `get_field_objects` | `api/api-template.php` |
| `get_fields` | `api/api-template.php` |
| `get_row` | `api/api-template.php` |
| `get_row_index` | `api/api-template.php` |
| `get_row_layout` | `api/api-template.php` |
| `get_sub_field` | `api/api-template.php` |
| `get_sub_field_object` | `api/api-template.php` |
| `has_sub_field` | `api/api-template.php` |
| `has_sub_fields` | `api/api-template.php` |
| `have_rows` | `api/api-template.php` |
| `reset_rows` | `api/api-template.php` |
| `the_field` | `api/api-template.php` |
| `the_row` | `api/api-template.php` |
| `the_row_index` | `api/api-template.php` |
| `the_sub_field` | `api/api-template.php` |
| `update_field` | `api/api-template.php` |
| `update_row` | `api/api-template.php` |
| `update_sub_field` | `api/api-template.php` |
| `update_sub_row` | `api/api-template.php` |

Notes:
- `get_row_layout()` is in the P0 list only so the importer and scanner know about it; per ADR-0008 NF does not implement it.
- Signatures are copied verbatim from ACF: `get_field( $selector, $post_id = false, $format_value = true, $escape_html = false )`, `the_field( $selector, $post_id = false, $format_value = true )`, `get_field_object( $selector, $post_id = false, $format_value = true, $load_value = true, $escape_html = false )`, `get_fields( $post_id = false, $format_value = true, $escape_html = false )`, `have_rows( $selector, $post_id = false )`, `the_row( $format = false )`, `get_sub_field( $selector = '', $format_value = true, $escape_html = false )`, `update_field( $selector, $value, $post_id = false )`, `add_row( $selector, $row = false, $post_id = false )`, `update_row( $selector, $i = 1, $row = false, $post_id = false )`, `delete_row( $selector, $i = 1, $post_id = false )`. The `$escape_html` parameter and the `_acf_log_escaped_html` behaviour (6.2.5+) must be matched.
- `$post_id` conventions to support: `false` (current post), int, `'option'`/`'options'`, `'options_<lang>'`, `'term_<id>'`, `'user_<id>'`, `'comment_<id>'`, `'widget_<id>'`, `'block_<id>'`, WP object instances (`WP_Post`, `WP_Term`, `WP_User`, `WP_Comment`).
- The loop state (`have_rows` / `the_row` / `reset_rows`) is global, nested and keyed by field and post ID (`includes/loop.php`); NF must reproduce nesting and `get_row_index()` 1-based indexing.

### P1: implement with the owning area (174 functions)

| Function | Source (ACF 6.8.11) |
| --- | --- |
| `acf_add_local_fields` | `local-fields.php` |
| `acf_add_local_internal_post_type` | `local-fields.php` |
| `acf_add_loop` | `loop.php` |
| `acf_build_bidirectional_relationship_field_target_args` | `acf-bidirectional-functions.php` |
| `acf_build_bidirectional_target_current_choices` | `acf-bidirectional-functions.php` |
| `acf_can_update_bidirectional_target` | `acf-bidirectional-functions.php` |
| `acf_clone_field` | `acf-field-functions.php` |
| `acf_copy_metadata` | `acf-meta-functions.php` |
| `acf_copy_postmeta` | `acf-meta-functions.php` |
| `acf_count_local_field_groups` | `local-fields.php` |
| `acf_count_local_fields` | `local-fields.php` |
| `acf_decode_term` | `api/api-term.php` |
| `acf_delete_field` | `acf-field-functions.php` |
| `acf_delete_field_group` | `acf-field-group-functions.php` |
| `acf_delete_json_field_group` | `local-json.php` |
| `acf_delete_metadata_by_field` | `acf-meta-functions.php` |
| `acf_delete_value` | `acf-value-functions.php` |
| `acf_disable_local` | `local-fields.php` |
| `acf_discard_uploaded_field_values` | `acf-form-functions.php` |
| `acf_duplicate_field` | `acf-field-functions.php` |
| `acf_duplicate_field_group` | `acf-field-group-functions.php` |
| `acf_duplicate_fields` | `acf-field-functions.php` |
| `acf_enable_local` | `local-fields.php` |
| `acf_encode_term` | `api/api-term.php` |
| `acf_field_group_has_location_type` | `acf-field-group-functions.php` |
| `acf_filter_field_groups` | `acf-field-group-functions.php` |
| `acf_filter_save_values` | `acf-form-functions.php` |
| `acf_flush_field_cache` | `acf-field-functions.php` |
| `acf_flush_field_group_cache` | `acf-field-group-functions.php` |
| `acf_flush_value_cache` | `acf-value-functions.php` |
| `acf_form_data` | `acf-form-functions.php` |
| `acf_format_value_for_rest` | `rest-api/acf-rest-api-functions.php` |
| `acf_get_bidirectional_field_settings_instruction_text` | `acf-bidirectional-functions.php` |
| `acf_get_choice_from_term` | `api/api-term.php` |
| `acf_get_choices_from_grouped_terms` | `api/api-term.php` |
| `acf_get_choices_from_terms` | `api/api-term.php` |
| `acf_get_combined_field_group_settings_tabs` | `acf-field-group-functions.php` |
| `acf_get_current_render_id` | `acf-form-functions.php` |
| `acf_get_encoded_terms` | `api/api-term.php` |
| `acf_get_field_ancestors` | `acf-field-functions.php` |
| `acf_get_field_count` | `acf-field-functions.php` |
| `acf_get_field_group_edit_link` | `acf-field-group-functions.php` |
| `acf_get_field_group_post` | `acf-field-group-functions.php` |
| `acf_get_field_group_style` | `acf-field-group-functions.php` |
| `acf_get_field_group_title` | `acf-field-group-functions.php` |
| `acf_get_field_group_visibility` | `acf-field-group-functions.php` |
| `acf_get_field_json_schema` | `acf-field-functions.php` |
| `acf_get_field_label` | `acf-field-functions.php` |
| `acf_get_field_post` | `acf-field-functions.php` |
| `acf_get_field_rest_links` | `rest-api/acf-rest-api-functions.php` |
| `acf_get_field_rest_schema` | `rest-api/acf-rest-api-functions.php` |
| `acf_get_field_root_key` | `acf-form-functions.php` |
| `acf_get_form` | `forms/form-front.php` |
| `acf_get_form_data` | `acf-form-functions.php` |
| `acf_get_forms` | `forms/form-front.php` |
| `acf_get_grouped_terms` | `api/api-term.php` |
| `acf_get_local_field` | `local-fields.php` |
| `acf_get_local_field_group` | `local-fields.php` |
| `acf_get_local_field_groups` | `local-fields.php` |
| `acf_get_local_fields` | `local-fields.php` |
| `acf_get_local_internal_post_type` | `local-fields.php` |
| `acf_get_local_internal_posts` | `local-fields.php` |
| `acf_get_local_json_files` | `local-json.php` |
| `acf_get_local_store` | `local-fields.php` |
| `acf_get_location_rule` | `locations.php` |
| `acf_get_location_rule_operators` | `locations.php` |
| `acf_get_location_rule_types` | `locations.php` |
| `acf_get_location_rule_values` | `locations.php` |
| `acf_get_location_screen` | `locations.php` |
| `acf_get_location_type` | `locations.php` |
| `acf_get_location_types` | `locations.php` |
| `acf_get_loop` | `loop.php` |
| `acf_get_meta` | `acf-meta-functions.php` |
| `acf_get_meta_field` | `acf-meta-functions.php` |
| `acf_get_meta_instance` | `acf-meta-functions.php` |
| `acf_get_metadata_by_field` | `acf-meta-functions.php` |
| `acf_get_metaref` | `acf-meta-functions.php` |
| `acf_get_option_meta` | `acf-meta-functions.php` |
| `acf_get_pretty_taxonomies` | `api/api-term.php` |
| `acf_get_raw_field` | `acf-field-functions.php` |
| `acf_get_raw_field_group` | `acf-field-group-functions.php` |
| `acf_get_raw_field_groups` | `acf-field-group-functions.php` |
| `acf_get_raw_fields` | `acf-field-functions.php` |
| `acf_get_reference` | `acf-value-functions.php` |
| `acf_get_sub_field` | `acf-field-functions.php` |
| `acf_get_taxonomies` | `api/api-term.php` |
| `acf_get_taxonomies_for_post_type` | `api/api-term.php` |
| `acf_get_taxonomy_labels` | `api/api-term.php` |
| `acf_get_term` | `api/api-term.php` |
| `acf_get_term_post_id` | `api/api-term.php` |
| `acf_get_term_title` | `api/api-term.php` |
| `acf_get_valid_bidirectional_target_types` | `acf-bidirectional-functions.php` |
| `acf_get_valid_field` | `acf-field-functions.php` |
| `acf_get_valid_field_group` | `acf-field-group-functions.php` |
| `acf_get_valid_location_rule` | `locations.php` |
| `acf_get_verified_field_keys` | `acf-form-functions.php` |
| `acf_have_local_field_groups` | `local-fields.php` |
| `acf_have_local_fields` | `local-fields.php` |
| `acf_import_field_group` | `acf-field-group-functions.php` |
| `acf_is_field` | `acf-field-functions.php` |
| `acf_is_field_group` | `acf-field-group-functions.php` |
| `acf_is_field_group_key` | `acf-field-group-functions.php` |
| `acf_is_field_key` | `acf-field-functions.php` |
| `acf_is_local_enabled` | `local-fields.php` |
| `acf_is_local_field` | `local-fields.php` |
| `acf_is_local_field_group` | `local-fields.php` |
| `acf_is_local_field_group_key` | `local-fields.php` |
| `acf_is_local_field_key` | `local-fields.php` |
| `acf_is_local_internal_post_type` | `local-fields.php` |
| `acf_is_local_internal_post_type_key` | `local-fields.php` |
| `acf_is_trusted_save_context` | `acf-form-functions.php` |
| `acf_log_invalid_field_notice` | `acf-value-functions.php` |
| `acf_map_post_meta_caps` | `acf-meta-functions.php` |
| `acf_match_location_rule` | `locations.php` |
| `acf_normalize_field_keys` | `acf-form-functions.php` |
| `acf_prefix_fields` | `acf-field-functions.php` |
| `acf_prepare_field` | `acf-field-functions.php` |
| `acf_prepare_field_for_export` | `acf-field-functions.php` |
| `acf_prepare_field_for_import` | `acf-field-functions.php` |
| `acf_prepare_field_group_for_export` | `acf-field-group-functions.php` |
| `acf_prepare_field_group_for_import` | `acf-field-group-functions.php` |
| `acf_prepare_fields_for_export` | `acf-field-functions.php` |
| `acf_prepare_fields_for_import` | `acf-field-functions.php` |
| `acf_preview_value` | `acf-value-functions.php` |
| `acf_register_form` | `forms/form-front.php` |
| `acf_register_location_rule` | `locations.php` |
| `acf_register_location_type` | `locations.php` |
| `acf_remove_local_field` | `local-fields.php` |
| `acf_remove_local_field_group` | `local-fields.php` |
| `acf_remove_local_internal_post_type` | `local-fields.php` |
| `acf_remove_loop` | `loop.php` |
| `acf_render_bidirectional_field_settings` | `acf-bidirectional-functions.php` |
| `acf_render_field` | `acf-field-functions.php` |
| `acf_render_field_instructions` | `acf-field-functions.php` |
| `acf_render_field_label` | `acf-field-functions.php` |
| `acf_render_field_setting` | `acf-field-functions.php` |
| `acf_render_field_wrap` | `acf-field-functions.php` |
| `acf_render_fields` | `acf-field-functions.php` |
| `acf_reset_local` | `local-fields.php` |
| `acf_rest_apply_reference_sanitizer` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_apply_user_data_sanitizer` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_reduce_icon_picker_reference` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_reduce_reference` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_reducible_reference_leaf_types` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_reference_is_exposable` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_sanitize_reference_data` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_sanitize_reference_sub_fields` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_sanitize_user_data` | `rest-api/acf-rest-api-functions.php` |
| `acf_rest_sanitize_user_sub_fields` | `rest-api/acf-rest-api-functions.php` |
| `acf_restrict_save_input` | `acf-form-functions.php` |
| `acf_restrict_uploaded_field_keys` | `acf-form-functions.php` |
| `acf_save_post` | `acf-form-functions.php` |
| `acf_search_fields` | `acf-field-functions.php` |
| `acf_set_form_data` | `acf-form-functions.php` |
| `acf_set_upload_render_ids` | `acf-form-functions.php` |
| `acf_translate_field` | `acf-field-functions.php` |
| `acf_translate_field_group` | `acf-field-group-functions.php` |
| `acf_trash_field` | `acf-field-functions.php` |
| `acf_trash_field_group` | `acf-field-group-functions.php` |
| `acf_untrash_field` | `acf-field-functions.php` |
| `acf_untrash_field_group` | `acf-field-group-functions.php` |
| `acf_update_bidirectional_values` | `acf-bidirectional-functions.php` |
| `acf_update_field` | `acf-field-functions.php` |
| `acf_update_field_group` | `acf-field-group-functions.php` |
| `acf_update_field_group_active_status` | `acf-field-group-functions.php` |
| `acf_update_loop` | `loop.php` |
| `acf_update_metadata_by_field` | `acf-meta-functions.php` |
| `acf_update_metaref` | `acf-meta-functions.php` |
| `acf_update_values` | `acf-value-functions.php` |
| `acf_validate_field` | `acf-field-functions.php` |
| `acf_validate_field_group` | `acf-field-group-functions.php` |
| `acf_validate_location_rule` | `locations.php` |
| `acf_write_json_field_group` | `local-json.php` |
| `register_field_group` | `local-fields.php` |

### P2: on demand (211 functions)

Listed in full below; implement only when a caller is found.

| Function | Source (ACF 6.8.11) |
| --- | --- |
| `acf_add_action_variations` | `acf-hook-functions.php` |
| `acf_add_array_key_prefix` | `api/api-helpers.php` |
| `acf_add_deprecated_action` | `acf-hook-functions.php` |
| `acf_add_deprecated_filter` | `acf-hook-functions.php` |
| `acf_add_filter_variations` | `acf-hook-functions.php` |
| `acf_add_url_utm_tags` | `acf-helper-functions.php` |
| `acf_add_validation_error` | `validation.php` |
| `acf_allow_unfiltered_html` | `acf-user-functions.php` |
| `acf_append_data` | `api/api-helpers.php` |
| `acf_append_setting` | `api/api-helpers.php` |
| `acf_apply_validate_is_image_attachment_filters` | `api/api-helpers.php` |
| `acf_array` | `api/api-helpers.php` |
| `acf_array_camel_case` | `api/api-helpers.php` |
| `acf_cache_key` | `acf-helper-functions.php` |
| `acf_clear_log` | `api/api-helpers.php` |
| `acf_connect_attachment_to_post` | `api/api-helpers.php` |
| `acf_convert_date_to_js` | `api/api-helpers.php` |
| `acf_convert_date_to_php` | `api/api-helpers.php` |
| `acf_convert_rules_to_groups` | `api/api-helpers.php` |
| `acf_convert_time_to_js` | `api/api-helpers.php` |
| `acf_convert_time_to_php` | `api/api-helpers.php` |
| `acf_current_user_can_admin` | `api/api-helpers.php` |
| `acf_current_user_can_edit_in_context` | `api/api-helpers.php` |
| `acf_current_user_can_edit_post` | `api/api-helpers.php` |
| `acf_current_user_can_read_post` | `api/api-helpers.php` |
| `acf_debug` | `api/api-helpers.php` |
| `acf_debug_end` | `api/api-helpers.php` |
| `acf_debug_start` | `api/api-helpers.php` |
| `acf_decode_choices` | `api/api-helpers.php` |
| `acf_decode_post_id` | `acf-wp-functions.php` |
| `acf_decode_taxonomy_term` | `api/api-helpers.php` |
| `acf_decode_taxonomy_terms` | `api/api-helpers.php` |
| `acf_decrypt` | `api/api-helpers.php` |
| `acf_dev_log` | `api/api-helpers.php` |
| `acf_did` | `acf-helper-functions.php` |
| `acf_disable_filter` | `acf-helper-functions.php` |
| `acf_disable_filters` | `acf-helper-functions.php` |
| `acf_doing` | `api/api-helpers.php` |
| `acf_doing_action` | `acf-helper-functions.php` |
| `acf_enable_filter` | `acf-helper-functions.php` |
| `acf_enable_filters` | `acf-helper-functions.php` |
| `acf_encode_choices` | `api/api-helpers.php` |
| `acf_encrypt` | `api/api-helpers.php` |
| `acf_ensure_perm_readable_post_status` | `api/api-helpers.php` |
| `acf_extract_var` | `api/api-helpers.php` |
| `acf_extract_vars` | `api/api-helpers.php` |
| `acf_field_type_exists` | `fields.php` |
| `acf_field_type_supports` | `fields.php` |
| `acf_format_date` | `api/api-helpers.php` |
| `acf_format_filesize` | `api/api-helpers.php` |
| `acf_format_numerics` | `acf-helper-functions.php` |
| `acf_get_array` | `api/api-helpers.php` |
| `acf_get_attachment_image` | `api/api-helpers.php` |
| `acf_get_browser` | `api/api-helpers.php` |
| `acf_get_combined_field_type_settings_tabs` | `fields.php` |
| `acf_get_compatibility` | `compatibility.php` |
| `acf_get_current_url` | `acf-helper-functions.php` |
| `acf_get_data` | `api/api-helpers.php` |
| `acf_get_external_dir` | `api/api-helpers.php` |
| `acf_get_external_path` | `api/api-helpers.php` |
| `acf_get_field_categories_i18n` | `fields.php` |
| `acf_get_field_type_label` | `fields.php` |
| `acf_get_field_type_prop` | `fields.php` |
| `acf_get_field_types` | `fields.php` |
| `acf_get_field_types_info` | `fields.php` |
| `acf_get_filesize` | `api/api-helpers.php` |
| `acf_get_filters` | `acf-helper-functions.php` |
| `acf_get_full_version` | `api/api-helpers.php` |
| `acf_get_grouped_field_types` | `fields.php` |
| `acf_get_grouped_posts` | `api/api-helpers.php` |
| `acf_get_grouped_users` | `api/api-helpers.php` |
| `acf_get_image_mime_types` | `api/api-helpers.php` |
| `acf_get_image_size` | `api/api-helpers.php` |
| `acf_get_instance` | `acf-utility-functions.php` |
| `acf_get_internal_post_types` | `api/api-helpers.php` |
| `acf_get_locale` | `l10n.php` |
| `acf_get_numeric` | `api/api-helpers.php` |
| `acf_get_object_id` | `acf-wp-functions.php` |
| `acf_get_object_type` | `acf-wp-functions.php` |
| `acf_get_object_type_rest_base` | `acf-wp-functions.php` |
| `acf_get_path` | `acf-utility-functions.php` |
| `acf_get_post_latest_revision` | `revisions.php` |
| `acf_get_post_stati` | `api/api-helpers.php` |
| `acf_get_post_status_label` | `api/api-helpers.php` |
| `acf_get_post_thumbnail` | `api/api-helpers.php` |
| `acf_get_post_title` | `api/api-helpers.php` |
| `acf_get_post_type_label` | `api/api-helpers.php` |
| `acf_get_post_types` | `api/api-helpers.php` |
| `acf_get_posts` | `api/api-helpers.php` |
| `acf_get_pretty_post_statuses` | `api/api-helpers.php` |
| `acf_get_pretty_post_types` | `api/api-helpers.php` |
| `acf_get_pretty_user_roles` | `api/api-helpers.php` |
| `acf_get_pro_field_types` | `fields.php` |
| `acf_get_restricted_post_title` | `api/api-helpers.php` |
| `acf_get_sites` | `api/api-helpers.php` |
| `acf_get_store` | `acf-utility-functions.php` |
| `acf_get_sub_array` | `api/api-helpers.php` |
| `acf_get_taxonomy_terms` | `api/api-helpers.php` |
| `acf_get_terms` | `api/api-helpers.php` |
| `acf_get_truncated` | `api/api-helpers.php` |
| `acf_get_url` | `acf-utility-functions.php` |
| `acf_get_user_result` | `acf-user-functions.php` |
| `acf_get_user_role_labels` | `acf-user-functions.php` |
| `acf_get_user_setting` | `api/api-helpers.php` |
| `acf_get_users` | `acf-user-functions.php` |
| `acf_get_valid_terms` | `api/api-helpers.php` |
| `acf_get_validation_error` | `validation.php` |
| `acf_get_validation_errors` | `validation.php` |
| `acf_get_version_when_first_activated` | `acf-helper-functions.php` |
| `acf_get_view` | `api/api-helpers.php` |
| `acf_get_wp_reserved_terms` | `api/api-helpers.php` |
| `acf_has_done` | `api/api-helpers.php` |
| `acf_has_setting` | `api/api-helpers.php` |
| `acf_idify` | `acf-helper-functions.php` |
| `acf_idval` | `acf-helper-functions.php` |
| `acf_in_array` | `api/api-helpers.php` |
| `acf_include` | `acf-utility-functions.php` |
| `acf_init` | `api/api-helpers.php` |
| `acf_insert_or_update_internal_post` | `acf-wp-functions.php` |
| `acf_is_acf_admin_screen` | `api/api-helpers.php` |
| `acf_is_ajax` | `api/api-helpers.php` |
| `acf_is_array` | `api/api-helpers.php` |
| `acf_is_associative_array` | `api/api-helpers.php` |
| `acf_is_beta` | `acf-helper-functions.php` |
| `acf_is_block_editor` | `api/api-helpers.php` |
| `acf_is_doing` | `api/api-helpers.php` |
| `acf_is_empty` | `acf-helper-functions.php` |
| `acf_is_field_type` | `fields.php` |
| `acf_is_filter_enabled` | `acf-helper-functions.php` |
| `acf_is_image_field` | `api/api-helpers.php` |
| `acf_is_multisite_main_site` | `api/api-helpers.php` |
| `acf_is_multisite_sub_site` | `api/api-helpers.php` |
| `acf_is_plugin_active` | `api/api-helpers.php` |
| `acf_is_row_collapsed` | `api/api-helpers.php` |
| `acf_is_screen` | `api/api-helpers.php` |
| `acf_is_sequential_array` | `api/api-helpers.php` |
| `acf_isset_termmeta` | `api/api-helpers.php` |
| `acf_json_encode` | `api/api-helpers.php` |
| `acf_load_textdomain` | `l10n.php` |
| `acf_log` | `api/api-helpers.php` |
| `acf_maybe_add_action` | `api/api-helpers.php` |
| `acf_maybe_get` | `api/api-helpers.php` |
| `acf_maybe_get_GET` | `api/api-helpers.php` |
| `acf_maybe_get_POST` | `api/api-helpers.php` |
| `acf_maybe_idval` | `acf-helper-functions.php` |
| `acf_maybe_unserialize` | `acf-helper-functions.php` |
| `acf_merge_attributes` | `acf-helper-functions.php` |
| `acf_merge_atts` | `api/api-helpers.php` |
| `acf_new_instance` | `acf-utility-functions.php` |
| `acf_nonce_input` | `api/api-helpers.php` |
| `acf_not_empty` | `acf-helper-functions.php` |
| `acf_numval` | `acf-helper-functions.php` |
| `acf_order_by_search` | `api/api-helpers.php` |
| `acf_parse_args` | `api/api-helpers.php` |
| `acf_parse_markdown` | `api/api-helpers.php` |
| `acf_parse_type` | `api/api-helpers.php` |
| `acf_parse_types` | `api/api-helpers.php` |
| `acf_plugin_dir_url` | `api/api-helpers.php` |
| `acf_post_password_required` | `api/api-helpers.php` |
| `acf_punctify` | `acf-helper-functions.php` |
| `acf_raw_setting` | `api/api-helpers.php` |
| `acf_register_ajax` | `api/api-helpers.php` |
| `acf_register_field_type_info` | `fields.php` |
| `acf_register_store` | `acf-utility-functions.php` |
| `acf_remove_array_key_prefix` | `api/api-helpers.php` |
| `acf_remove_disallowed_post_ids` | `api/api-helpers.php` |
| `acf_remove_unreadable_formatted_post_ids` | `api/api-helpers.php` |
| `acf_remove_unreadable_grouped_posts` | `api/api-helpers.php` |
| `acf_request_arg` | `acf-helper-functions.php` |
| `acf_request_args` | `acf-helper-functions.php` |
| `acf_reset_meta` | `local-meta.php` |
| `acf_reset_validation_errors` | `validation.php` |
| `acf_sanitize_files_array` | `acf-helper-functions.php` |
| `acf_sanitize_files_value_array` | `acf-helper-functions.php` |
| `acf_sanitize_request_args` | `acf-helper-functions.php` |
| `acf_save_post_revision` | `revisions.php` |
| `acf_send_ajax_results` | `api/api-helpers.php` |
| `acf_set_data` | `api/api-helpers.php` |
| `acf_set_filters` | `acf-helper-functions.php` |
| `acf_settings_enable_meta_box_cb_edit` | `api/api-helpers.php` |
| `acf_setup_meta` | `local-meta.php` |
| `acf_skip_internal_post_sanitization` | `acf-wp-functions.php` |
| `acf_slugify` | `acf-helper-functions.php` |
| `acf_split_date_time` | `api/api-helpers.php` |
| `acf_str_camel_case` | `api/api-helpers.php` |
| `acf_str_exists` | `api/api-helpers.php` |
| `acf_str_replace` | `api/api-helpers.php` |
| `acf_strip_protocol` | `api/api-helpers.php` |
| `acf_strlen` | `acf-helper-functions.php` |
| `acf_switch_stores` | `acf-utility-functions.php` |
| `acf_translate` | `api/api-helpers.php` |
| `acf_unarray` | `api/api-helpers.php` |
| `acf_uniqid` | `acf-helper-functions.php` |
| `acf_update_nested_array` | `api/api-helpers.php` |
| `acf_update_user_setting` | `api/api-helpers.php` |
| `acf_upload_file` | `api/api-helpers.php` |
| `acf_upload_files` | `api/api-helpers.php` |
| `acf_user_can_read_post` | `api/api-helpers.php` |
| `acf_validate_attachment` | `api/api-helpers.php` |
| `acf_validate_is_image_attachment` | `api/api-helpers.php` |
| `acf_validate_is_image_attachment_basic_upload` | `api/api-helpers.php` |
| `acf_validate_is_image_attachment_prepare` | `api/api-helpers.php` |
| `acf_validate_is_image_attachment_upload` | `api/api-helpers.php` |
| `acf_validate_save_post` | `validation.php` |
| `acf_validate_setting` | `api/api-helpers.php` |
| `acf_validate_value` | `validation.php` |
| `acf_validate_values` | `validation.php` |
| `acf_verify_ajax` | `api/api-helpers.php` |
| `acf_verify_nonce` | `api/api-helpers.php` |
| `acf_version_compare` | `api/api-helpers.php` |
| `acf_with_default` | `acf-helper-functions.php` |

### P3: not implemented (148 functions)

Admin, upgrade, internal post-type and flexible-content helpers. Full list:

| Function | Source (ACF 6.8.11) |
| --- | --- |
| `acf_add_admin_notice` | `admin/admin-notices.php` |
| `acf_add_internal_post_type_validation_error` | `acf-internal-post-type-functions.php` |
| `acf_bindings_flatten_value` | `api/api-template.php` |
| `acf_bindings_reduce_user_value` | `api/api-template.php` |
| `acf_bindings_should_reduce_user_data` | `api/api-template.php` |
| `acf_checkbox_input` | `acf-input-functions.php` |
| `acf_clean_atts` | `acf-input-functions.php` |
| `acf_delete_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_delete_post_type` | `acf-post-type-functions.php` |
| `acf_delete_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_determine_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_duplicate_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_duplicate_post_type` | `acf-post-type-functions.php` |
| `acf_duplicate_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_enqueue_script` | `assets.php` |
| `acf_enqueue_scripts` | `assets.php` |
| `acf_enqueue_uploader` | `assets.php` |
| `acf_esc_attr` | `acf-input-functions.php` |
| `acf_esc_attr_e` | `acf-input-functions.php` |
| `acf_esc_attrs` | `acf-input-functions.php` |
| `acf_esc_atts` | `acf-input-functions.php` |
| `acf_esc_atts_e` | `acf-input-functions.php` |
| `acf_esc_html` | `acf-input-functions.php` |
| `acf_export_enter_title_here` | `acf-post-type-functions.php` |
| `acf_export_internal_post_type_as_php` | `acf-internal-post-type-functions.php` |
| `acf_export_post_type_as_php` | `acf-post-type-functions.php` |
| `acf_export_taxonomy_as_php` | `acf-taxonomy-functions.php` |
| `acf_file_input` | `acf-input-functions.php` |
| `acf_filter_attrs` | `acf-input-functions.php` |
| `acf_filter_internal_post_type_posts` | `acf-internal-post-type-functions.php` |
| `acf_filter_post_id` | `api/api-template.php` |
| `acf_filter_post_types` | `acf-post-type-functions.php` |
| `acf_filter_taxonomies` | `acf-taxonomy-functions.php` |
| `acf_flush_internal_post_type_cache` | `acf-internal-post-type-functions.php` |
| `acf_flush_post_type_cache` | `acf-post-type-functions.php` |
| `acf_flush_taxonomy_cache` | `acf-taxonomy-functions.php` |
| `acf_get_acf_post_types` | `acf-post-type-functions.php` |
| `acf_get_acf_taxonomies` | `acf-taxonomy-functions.php` |
| `acf_get_admin_tool_url` | `admin/admin-tools.php` |
| `acf_get_admin_tools_url` | `admin/admin-tools.php` |
| `acf_get_checkbox_input` | `acf-input-functions.php` |
| `acf_get_combined_options_page_settings_tabs` | `acf-internal-post-type-functions.php` |
| `acf_get_combined_post_type_settings_tabs` | `acf-internal-post-type-functions.php` |
| `acf_get_combined_taxonomy_settings_tabs` | `acf-internal-post-type-functions.php` |
| `acf_get_db_version` | `upgrades.php` |
| `acf_get_dir` | `deprecated.php` |
| `acf_get_field_reference` | `deprecated.php` |
| `acf_get_fields_by_id` | `deprecated.php` |
| `acf_get_file_input` | `acf-input-functions.php` |
| `acf_get_file_uploader_nonce_action` | `acf-input-functions.php` |
| `acf_get_hidden_input` | `acf-input-functions.php` |
| `acf_get_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_get_internal_post_type_edit_link` | `acf-internal-post-type-functions.php` |
| `acf_get_internal_post_type_instance` | `acf-internal-post-type-functions.php` |
| `acf_get_internal_post_type_post` | `acf-internal-post-type-functions.php` |
| `acf_get_internal_post_type_posts` | `acf-internal-post-type-functions.php` |
| `acf_get_post_type` | `acf-post-type-functions.php` |
| `acf_get_post_type_edit_link` | `acf-post-type-functions.php` |
| `acf_get_post_type_from_request_args` | `acf-internal-post-type-functions.php` |
| `acf_get_post_type_from_screen_value` | `acf-internal-post-type-functions.php` |
| `acf_get_post_type_post` | `acf-post-type-functions.php` |
| `acf_get_radio_input` | `acf-input-functions.php` |
| `acf_get_raw_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_get_raw_internal_post_type_posts` | `acf-internal-post-type-functions.php` |
| `acf_get_raw_post_type` | `acf-post-type-functions.php` |
| `acf_get_raw_post_types` | `acf-post-type-functions.php` |
| `acf_get_raw_taxonomies` | `acf-taxonomy-functions.php` |
| `acf_get_raw_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_get_select_input` | `acf-input-functions.php` |
| `acf_get_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_get_taxonomy_edit_link` | `acf-taxonomy-functions.php` |
| `acf_get_taxonomy_from_request_args` | `acf-internal-post-type-functions.php` |
| `acf_get_taxonomy_post` | `acf-taxonomy-functions.php` |
| `acf_get_text_input` | `acf-input-functions.php` |
| `acf_get_textarea_input` | `acf-input-functions.php` |
| `acf_get_ui_options_page_from_request_args` | `acf-internal-post-type-functions.php` |
| `acf_has_upgrade` | `upgrades.php` |
| `acf_hidden_input` | `acf-input-functions.php` |
| `acf_import_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_import_post_type` | `acf-post-type-functions.php` |
| `acf_import_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_internal_post_object_contains_valid_key` | `acf-internal-post-type-functions.php` |
| `acf_is_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_is_internal_post_type_key` | `acf-internal-post-type-functions.php` |
| `acf_is_post_type` | `acf-post-type-functions.php` |
| `acf_is_post_type_key` | `acf-post-type-functions.php` |
| `acf_is_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_is_taxonomy_key` | `acf-taxonomy-functions.php` |
| `acf_is_valid_internal_post_type_key` | `acf-internal-post-type-functions.php` |
| `acf_localize_data` | `assets.php` |
| `acf_localize_text` | `assets.php` |
| `acf_maybe_get_field` | `api/api-template.php` |
| `acf_maybe_get_sub_field` | `api/api-template.php` |
| `acf_new_admin_notice` | `admin/admin-notices.php` |
| `acf_prepare_internal_post_type_for_export` | `acf-internal-post-type-functions.php` |
| `acf_prepare_internal_post_type_for_import` | `acf-internal-post-type-functions.php` |
| `acf_prepare_post_type_for_export` | `acf-post-type-functions.php` |
| `acf_prepare_post_type_for_import` | `acf-post-type-functions.php` |
| `acf_prepare_taxonomy_for_export` | `acf-taxonomy-functions.php` |
| `acf_prepare_taxonomy_for_import` | `acf-taxonomy-functions.php` |
| `acf_print_menu_section` | `admin/views/global/navigation.php` |
| `acf_radio_input` | `acf-input-functions.php` |
| `acf_register_admin_tool` | `admin/admin-tools.php` |
| `acf_render_admin_notices` | `admin/admin-notices.php` |
| `acf_render_field_wrap_description` | `deprecated.php` |
| `acf_render_field_wrap_label` | `deprecated.php` |
| `acf_select_input` | `acf-input-functions.php` |
| `acf_text_input` | `acf-input-functions.php` |
| `acf_textarea_input` | `acf-input-functions.php` |
| `acf_translate_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_translate_post_type` | `acf-post-type-functions.php` |
| `acf_translate_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_trash_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_trash_post_type` | `acf-post-type-functions.php` |
| `acf_trash_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_untrash_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_untrash_post_type` | `acf-post-type-functions.php` |
| `acf_untrash_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_update_db_version` | `upgrades.php` |
| `acf_update_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_update_internal_post_type_active_status` | `acf-internal-post-type-functions.php` |
| `acf_update_option` | `deprecated.php` |
| `acf_update_post_type` | `acf-post-type-functions.php` |
| `acf_update_post_type_active_status` | `acf-post-type-functions.php` |
| `acf_update_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_update_taxonomy_active_status` | `acf-taxonomy-functions.php` |
| `acf_upgrade_500` | `upgrades.php` |
| `acf_upgrade_500_field` | `upgrades.php` |
| `acf_upgrade_500_field_group` | `upgrades.php` |
| `acf_upgrade_500_field_groups` | `upgrades.php` |
| `acf_upgrade_500_fields` | `upgrades.php` |
| `acf_upgrade_550` | `upgrades.php` |
| `acf_upgrade_550_taxonomy` | `upgrades.php` |
| `acf_upgrade_550_termmeta` | `upgrades.php` |
| `acf_upgrade_all` | `upgrades.php` |
| `acf_validate_internal_post_type` | `acf-internal-post-type-functions.php` |
| `acf_validate_internal_post_type_values` | `acf-internal-post-type-functions.php` |
| `acf_validate_post_type` | `acf-post-type-functions.php` |
| `acf_validate_taxonomy` | `acf-taxonomy-functions.php` |
| `acf_walk_select_input` | `acf-input-functions.php` |
| `acf_wp_upgrade_550_termmeta` | `upgrades.php` |
| `create_field` | `api/api-template.php` |
| `get_row_sub_field` | `api/api-template.php` |
| `get_row_sub_value` | `api/api-template.php` |
| `render_field` | `api/api-template.php` |
| `reset_the_repeater_field` | `api/api-template.php` |
| `the_flexible_field` | `api/api-template.php` |
| `the_repeater_field` | `api/api-template.php` |

### Hooks

Hooks must fire at the same points with the same arguments (design doc, *Surface NF implements*). Ranking: the per-field-lifecycle filters (`acf/load_value`, `acf/format_value`, `acf/update_value`, `acf/validate_value`, `acf/load_field`, `acf/prepare_field`, `acf/update_field`, `acf/pre_load_metadata`, `acf/pre_update_metadata`, `acf/pre_delete_metadata`, `acf/save_post`, `acf/init`, `acf/include_fields`, `acf/settings/*`) are P0; the rest are P1 where they relate to registration, Local JSON, location rules, blocks, forms and REST, and P3 for admin screens.

Filters (183):

`acf/acf_get_posts/args`, `acf/acf_get_posts/results`, `acf/admin/acf_url_utm_parameters`, `acf/admin/prevent_escaped_html_notice`, `acf/admin/show_email_opt_in_banner`, `acf/admin/toolbar`, `acf/ajax/check_screen/response`, `acf/ajax/query_users/args`, `acf/ajax/query_users/result`, `acf/ajax/query_users/results`, `acf/ajax/query_users/search_columns`, `acf/ajax/shortcode_capability`, `acf/allow_unfiltered_html`, `acf/bidirectional/can_update_target`, `acf/bidirectional/supported_field_types_for_post`, `acf/bidirectional/supported_target_field_types`, `acf/bindings/allow_user_data`, `acf/clone_field`, `acf/conditional_logic/choices`, `acf/connect_attachment_to_post`, `acf/current_user_can_edit_in_context`, `acf/current_user_can_edit_post`, `acf/decode_post_id`, `acf/duplicate_field`, `acf/field_group/additional_field_settings_tabs`, `acf/field_group/additional_group_settings_tabs`, `acf/field_group/admin_l10n`, `acf/field_group/auto_add_first_field`, `acf/field_group/disable_field_settings_tabs`, `acf/field_group/enable_field_browser`, `acf/field_group/enable_field_type_select2`, `acf/field_group/list_table_classes`, `acf/field_group/prefill_title`, `acf/field_wrapper_attributes`, `acf/fields/allow_append_choices`, `acf/fields/google_map/api`, `acf/fields/icon_picker/`, `acf/fields/icon_picker/dashicons`, `acf/fields/icon_picker/tabs`, `acf/fields/max_appended_choices`, `acf/fields/oembed/allow_discovery`, `acf/fields/page_link/query`, `acf/fields/page_link/query/key=`, `acf/fields/page_link/query/name=`, `acf/fields/page_link/result`, `acf/fields/page_link/result/key=`, `acf/fields/page_link/result/name=`, `acf/fields/post_object/query`, `acf/fields/post_object/query/key=`, `acf/fields/post_object/query/name=`, `acf/fields/post_object/result`, `acf/fields/post_object/result/key=`, `acf/fields/post_object/result/name=`, `acf/fields/relational/hide_unreadable_posts`, `acf/fields/relational/unreadable_post_statuses`, `acf/fields/relationship/query`, `acf/fields/relationship/query/key=`, `acf/fields/relationship/query/name=`, `acf/fields/relationship/result`, `acf/fields/relationship/result/key=`, `acf/fields/relationship/result/name=`, `acf/fields/select/query`, `acf/fields/select/query/key=`, `acf/fields/taxonomy/query`, `acf/fields/taxonomy/result`, `acf/fields/taxonomy/wp_list_categories`, `acf/fields/taxonomy/wp_list_categories/key=`, `acf/fields/taxonomy/wp_list_categories/name=`, `acf/fields/user/query`, `acf/fields/user/result`, `acf/fields/user/search_columns`, `acf/fields/wysiwyg/toolbars`, `acf/filesize`, `acf/form-post/skip_save`, `acf/form/allowed_field_keys`, `acf/form/meta_ttl`, `acf/form/primary_ttl`, `acf/format_value`, `acf/get_cache_key`, `acf/get_field_count`, `acf/get_field_group_style`, `acf/get_field_group_title`, `acf/get_field_label`, `acf/get_field_types`, `acf/get_image_mime_types`, `acf/get_image_sizes`, `acf/get_locale`, `acf/get_object_type`, `acf/get_post_id_info`, `acf/get_post_stati`, `acf/get_post_types`, `acf/get_sub_field`, `acf/get_taxonomies`, `acf/input/admin_l10n`, `acf/input/meta_box_priority`, `acf/internal_post_type/admin_body_classes`, `acf/internal_post_type_list/admin_body_classes`, `acf/is_field_key`, `acf/is_image_field`, `acf/is_trusted_save_context`, `acf/json/eof_newline`, `acf/json/load_paths`, `acf/json/save_file_name`, `acf/json/save_paths`, `acf/load_attachment`, `acf/load_field`, `acf/load_fields`, `acf/load_meta`, `acf/load_reference`, `acf/load_value`, `acf/localized_field_categories`, `acf/location/match_rule`, `acf/location/rule_match`, `acf/location/rule_operators`, `acf/location/rule_types`, `acf/location/rule_values`, `acf/location/screen`, `acf/location/validate_rule`, `acf/post_type/additional_settings_tabs`, `acf/post_type/admin_l10n`, `acf/post_type/available_supports`, `acf/post_type/prevent_access_to_wp_functions_in_meta_box_cb`, `acf/post_type/registration_args`, `acf/pre_delete_metadata`, `acf/pre_format_value`, `acf/pre_load_attachment`, `acf/pre_load_meta`, `acf/pre_load_metadata`, `acf/pre_load_post_id`, `acf/pre_load_reference`, `acf/pre_load_value`, `acf/pre_render_field`, `acf/pre_render_fields`, `acf/pre_save_json_file`, `acf/pre_save_post`, `acf/pre_submit_form`, `acf/pre_update_metadata`, `acf/pre_update_value`, `acf/prepare_field`, `acf/prepare_field_for_export`, `acf/prepare_field_for_import`, `acf/prepare_fields_for_import`, `acf/prevent_access_to_unknown_fields`, `acf/preview_value`, `acf/rest/expose_reference_target`, `acf/rest/format_value_for_rest`, `acf/rest/get_field_links`, `acf/rest/get_field_schema`, `acf/rest/get_fields`, `acf/revisions/skip_legacy_metabox_handling`, `acf/shortcode/allow_in_block_themes_outside_content`, `acf/shortcode/allow_unsafe_html`, `acf/shortcode/disabled_message`, `acf/shortcode/field_not_allowed_message`, `acf/shortcode/field_not_supported_message`, `acf/shortcode/post_not_public_message`, `acf/shortcode/prevent_access`, `acf/shortcode/prevent_access_to_fields_on_non_public_posts`, `acf/shortcode/preview_capability`, `acf/shortcode/preview_capability_message`, `acf/slugify`, `acf/taxonomy/additional_settings_tabs`, `acf/taxonomy/admin_l10n`, `acf/taxonomy/prevent_access_to_wp_functions_in_meta_box_cb`, `acf/taxonomy/registration_args`, `acf/the_field/allow_unsafe_html`, `acf/translate_field`, `acf/ui_options_page/additional_settings_tabs`, `acf/update_field`, `acf/update_value`, `acf/upload/generate_previews`, `acf/upload_prefilter`, `acf/user_can_read_post`, `acf/validate_attachment`, `acf/validate_field`, `acf/validate_form`, `acf/validate_is_image_attachment`, `acf/validate_is_image_attachment/error_message`, `acf/validate_is_image_attachment/vector_mime_types`, `acf/validate_post_id`, `acf/validate_rest_value/type=`, `acf/validate_setting`, `acf/validate_value`

Actions (65):

`acf/add_meta_boxes`, `acf/admin_enqueue_scripts`, `acf/admin_footer`, `acf/admin_head`, `acf/admin_print_footer_scripts`, `acf/admin_print_scripts`, `acf/admin_print_uploader_scripts`, `acf/ajax/query_users/init`, `acf/delete_field`, `acf/delete_value`, `acf/enqueue_scripts`, `acf/enqueue_uploader`, `acf/field_group/admin_enqueue_scripts`, `acf/field_group/admin_footer`, `acf/field_group/admin_head`, `acf/field_group/form_data`, `acf/field_group/render_additional_group_settings`, `acf/field_group/render_additional_location_settings`, `acf/field_group/render_additional_presentation_settings`, `acf/field_group/render_group_settings_tab/`, `acf/fields/icon_picker/tab/`, `acf/form_data`, `acf/get_invalid_field_value`, `acf/in_admin_header`, `acf/include_admin_tools`, `acf/input/admin_enqueue_scripts`, `acf/input/admin_footer`, `acf/input/admin_head`, `acf/input/admin_print_footer_scripts`, `acf/input/admin_print_scripts`, `acf/input/form_data`, `acf/internal_post_type/current_screen`, `acf/internal_post_type_list/current_screen`, `acf/post_type/admin_enqueue_scripts`, `acf/post_type/admin_footer`, `acf/post_type/admin_head`, `acf/post_type/basic_settings`, `acf/post_type/form_data`, `acf/register_scripts`, `acf/registered_location_type`, `acf/removed_unsafe_html`, `acf/render_field`, `acf/render_field_group_settings`, `acf/render_field_settings`, `acf/render_fields`, `acf/save_post`, `acf/submit_form`, `acf/taxonomy/admin_enqueue_scripts`, `acf/taxonomy/admin_footer`, `acf/taxonomy/admin_head`, `acf/taxonomy/basic_settings`, `acf/taxonomy/form_data`, `acf/trash_field`, `acf/untrash_field`, `acf/update_500_field`, `acf/update_field_group`, `acf/updated_field`, `acf/upgrade_500`, `acf/upgrade_500_field_group`, `acf/upgrade_550`, `acf/upgrade_550_taxonomy`, `acf/upgrade_550_taxonomy_term`, `acf/upgrade_550_termmeta`, `acf/validate_save_post`, `acf/verify_ajax`

Each of these also has `/name=`, `/key=` and `/type=` variants where ACF fires them (`acf/load_value/name=x`, and so on).

### Other surface

- **Classes themes check:** `ACF` (`class_exists( 'ACF' )`), `acf_field` (custom field type base), `acf_pro` is not needed. Constants: `ACF`, `ACF_VERSION`, `ACF_PATH`, `ACF_PRO` (only when mimicking PRO).
- **Shortcode:** `[acf field="..." post_id="..." format_value="..."]` (`acf_shortcode`).
- **Post types in bridge mode:** `acf-field-group`, `acf-field`, `acf-post-type`, `acf-taxonomy`, `acf-ui-options-page`.
- **Options:** `acf_version`, `acf_pro_license`, `acf_ui_options_pages` and similar are not read by NF.
- **Template tags in Timber:** `get_field` via `pre_meta` hooks (A7).

## Public NF API

### Block Bindings (ADR-0012, WP 4.11)

```php
/**
 * Make a block type's attributes bindable to NF fields.
 * Wraps core's block_bindings_supported_attributes_{$block_type} filter (WP 6.9+).
 *
 * @param string   $block_type Block name, e.g. 'my/event-card'.
 * @param string[] $attributes Attribute names declared in the block's block.json.
 * @return bool False if the block type is unknown or an attribute does not exist
 *              (with _doing_it_wrong() under WP_DEBUG).
 */
function nf_register_bindable_attributes( string $block_type, array $attributes ): bool;
```

- The block.json equivalent is `"nf": { "bindable": [ ... ] }`, read when the block is registered.
- It lives in `src/functions/bindings.php`, guarded by `function_exists()`, and delegates to `NF\Bindings\Registry`.
- **Filter `nf/bindings/attributes`:** `( string[] $attributes, string $block_type, array $field ): string[]`. It narrows the attributes the bindings UI offers for one field. It runs only for blocks that NF binds.

## Internal boundaries

Store, compiler, loader and registry, plus the backend adapter `nf_db()` and the SQL dialect seam (MySQL now, PostgreSQL in Phase 5; ADR-0009). WP 0.5 defines them so PostgreSQL is an added dialect, not a rewrite.

## Hooks

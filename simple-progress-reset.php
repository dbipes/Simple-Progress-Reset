<?php
/*
Plugin Name: Simple Progress Reset
Description: Resets user progress
Version: 1.0
*/

function llms_get_enrollments($args) {
  // Query enrollments
  $defaults = array(
    'user' => null, 
    'post_parent' => null,
  );

  $args = wp_parse_args( $args, $defaults );

  $enrollments_query = new LLMS_Query_User_Enrollments($args);
  
  return $enrollments_query->get_enrollments();
}

function llms_user_enrolled_in_course($user_id, $course_id) {
  $user = llms_get_student($user_id);
  if (!$user) {
    return false;
  }

  $enrollments = $user->get_enrollments('courses', $course_id);

  if (empty($enrollments)) {
    return false;
  }

  return true;
}

function llms_get_user_courses($student_id) {

  $courses = get_posts(array(
    'fields' => 'ids',
    'post_type' => 'course',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'meta_query' => array(
      array(
        'key' => '_llms_restricted_levels',
        'compare' => 'EXISTS',
      ),
    ),
    'meta_query' => array(
      'relation' => 'OR',
      array(
        'key' => '_llms_restriction_rollout_date',
        'value' => current_time('timestamp'),
        'type' => 'numeric',
        'compare' => '<=',
      ),
      array(
        'key' => '_llms_restriction_rollout_date',
        'compare' => 'NOT EXISTS',
      ),
    ),
    'tax_query' => array(
      array(
        'taxonomy' => 'course_visibility',
        'field' => 'name',
        'terms' => 'catalog',
        'operator' => 'IN',
      ),
    ),
  ));

  if ($courses) {

    $enrollments = llms_get_enrollments(array(
      'user' => $student_id,
      'post_type' => 'course',
      'post_status' => 'publish',
    ));

    if ($enrollments) {
      $courses = wp_list_pluck($enrollments, 'parent_id');
    }

  }

  return get_posts(array(
    'include' => $courses,
    'post_type' => 'course',
  ));

}

class Simple_Progress_Reset {
  private $error_message;
  private $success_message;
  private $reset_log;

  public function __construct() {
    $this->error_message = '';
    $this->success_message = '';
    $this->reset_log = array();
    add_action('wp_footer', array($this, 'display_success_message_popup'));
    add_action('admin_menu', array($this, 'add_admin_menu'));
  }

  public function add_admin_menu() {
    add_submenu_page(
      'options-general.php',
      'Simple Progress Reset',
      'Simple Progress Reset',
      'manage_options',
      'simple-progress-reset',
      array($this, 'create_reset_page')
    );
  }

  public function reset_user_progress($user_id) {
    if (!is_numeric($user_id) || $user_id <= 0) {
      $this->log_error('Invalid user ID.');
      return;
    }

    update_user_meta($user_id, 'llms_overall_grade', 0);
    update_user_meta($user_id, 'llms_overall_progress', 0);

    $courses_info = llms_get_user_courses($user_id);
    if ($courses_info) {
      $total_courses = count($courses_info);
      foreach ($courses_info as $index => $course_info) {
        $course_id = $course_info->ID;
        $course_name = $course_info->post_title;

        $this->log_progress("Resetting progress for Course: {$course_name} (ID: {$course_id}) [Step {$index} of {$total_courses}]");
        $this->reset_course_progress($user_id, $course_id, $course_name);
      }
    } else {
      $this->log_error('User is not enrolled in any course.');
    }

    if (empty($this->error_message)) {
      $this->log_success('Progress reset completed successfully.');
    }
  }

  private function reset_course_progress($user_id, $course_id, $course_name) {
    global $wpdb;
    $course_lessons = get_post_meta($course_id, '_lifterlms_lesson_order', true);
    if ($course_lessons) {
      $total_lessons = count($course_lessons);
      foreach ($course_lessons as $index => $lesson_id) {
        $this->log_progress("Starting reset for Lesson ID: {$lesson_id} in Course: {$course_name} (ID: {$course_id}) [Step {$index} of {$total_lessons}]");

        $result = $wpdb->query("DELETE FROM {$wpdb->prefix}lifterlms_user_postmeta WHERE meta_key IN ('_is_complete', '_completion_trigger') AND user_id = $user_id AND post_id IN ($lesson_id, $course_id)");

        if (false === $result) {
          $this->log_error("Error resetting lesson progress for Lesson ID: {$lesson_id} in Course: {$course_name} (ID: {$course_id}) [Step {$index} of {$total_lessons}]");
        } else {
          $this->log_success("Reset lesson progress for Lesson ID: {$lesson_id} in Course: {$course_name} (ID: {$course_id}) [Step {$index} of {$total_lessons}]");
        }

        $this->reset_log[] = array(
          'course_name' => $course_name,
          'course_id' => $course_id,
          'lesson_id' => $lesson_id,
          'status' => $result ? 'success' : 'failure'
        );
      }
    }

    $wpdb->query("DELETE FROM {$wpdb->prefix}lifterlms_notifications WHERE user_id = $user_id AND post_id = $course_id");

    if ($course_lessons) {
      $total_lessons = count($course_lessons);
      foreach ($course_lessons as $index => $lesson_id) {
        $this->log_progress("Starting reset for quiz attempts in Lesson ID: {$lesson_id} of Course: {$course_name} (ID: {$course_id}) [Step {$index} of {$total_lessons}]");
        $wpdb->query("DELETE FROM {$wpdb->prefix}lifterlms_quiz_attempts WHERE lesson_id = $lesson_id AND student_id = $user_id");
        $this->log_progress("Reset quiz attempts for Lesson ID: {$lesson_id} in Course: {$course_name} (ID: {$course_id}) [Step {$index} of {$total_lessons}]");
      }
    }

    $this->reset_course_average_grade($course_id);

    $this->success_message .= 'Course progress reset for Course: ' . $course_name . ' (ID: ' . $course_id . ')' . PHP_EOL;
  }

  private function reset_course_average_grade($course_id) {
    global $wpdb;
    $grades = get_post_meta($course_id, '_llms_grades', true);
    if (!empty($grades) && is_array($grades)) {
      $total_grades = array_sum($grades);
      $average_grade = count($grades) > 0 ? round($total_grades / count($grades), 2) : 0;
      update_post_meta($course_id, '_llms_average_grade', $average_grade);
    } else {
      update_post_meta($course_id, '_llms_average_grade', 0);
    }
  }

  public function log_error($message) {
    $this->reset_log[] = '<strong>Error:</strong> ' . $message;
    error_log('Simple Progress Reset Error: ' . $message);
  }

  public function log_success($message) {
    $this->reset_log[] = '<strong>Success:</strong> ' . $message;
    error_log('Simple Progress Reset Success: ' . $message);
  }

  public function log_progress($message) {
    $this->reset_log[] = $message;
    echo '<script>console.log("' . esc_js($message) . '");</script>';
  }

  public function display_notification() {
    if (!empty($this->error_message)) {
      echo '<div class="error notice"><p>' . esc_html($this->error_message) . '</p></div>';
    }
  }

  public function create_reset_page() {
    $current_user_id = is_user_logged_in() ? get_current_user_id() : 0;

    if (isset($_POST['reset_user_id'])) {
      $user_id = absint($_POST['reset_user_id']);
      $this->reset_user_progress($user_id);
    }

    if (isset($_POST['check_db_access'])) {
      $this->check_db_access();
    }

    ?>
    <div class="wrap">
      <h1>Simple Progress Reset</h1>
      <?php $this->display_notification(); ?>
      <form method="post">
        <label for="reset_user_id">Enter User ID:</label>
        <input type="number" id="reset_user_id" name="reset_user_id" required value="<?php echo esc_attr($current_user_id); ?>">
        <button type="submit" class="button button-primary">Reset Progress</button>
      </form>

      <form method="post">
        <button type="submit" class="button" name="check_db_access">Check Database Access</button>
      </form>

      <div class="reset-log">
        <h2>Reset Log</h2>
        <ul>
          <?php foreach ($this->reset_log as $log_entry) : ?>
            <li>
              <?php echo $log_entry; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php
  }

  public function check_db_access() {
    global $wpdb;
    $db_check_result = isset($wpdb) && $wpdb instanceof wpdb ? 'Database access is properly defined.' : 'Database access is NOT properly defined.';
    $this->log_progress($db_check_result);
  }

  public function display_success_message_popup() {
    if (!empty($this->success_message)) {
      echo '<script>alert("' . esc_js($this->success_message) . '");</script>';
    }
  }
}

$simple_progress_reset = new Simple_Progress_Reset();

add_shortcode('reset_progress_form', array($simple_progress_reset, 'create_reset_page'));
?>

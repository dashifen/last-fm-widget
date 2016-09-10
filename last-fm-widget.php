<?php
/*
 * Plugin Name:       Dashifen's Last.fm Widget
 * Description:       A small plugin that creates a Last.fm widget the way Dashifen likes it.
 * Author:            David Dashifen Kees
 * Author URI:        http://dashifen.com
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * License:           GPL-2.0+
 * Version:           1.0.1
 */

if (!defined('WPINC')) {
	die;
}

class dashifen_last_fm_widget extends WP_Widget {
	public function __construct() {
		$widget = array(
			"classname"   => "dashifen-last-fm-widget",
			"description" => "A widget showing the most recent song on a last.fm profile.",
		);

		parent::__construct("dashifen-last-fm-widget", "Last.fm Widget", $widget);
	}

	public function widget($args, $instance) {
		extract($instance);		// creates last_fm_title, last_fm_user, last_fm_api_key
		extract($args);			// creates before_widget, before_title, after_title, after_widget

		$widget = "";
		$song_data = $this->get_recent_song_from_last_fm($last_fm_user, $last_fm_api_key);
		if ($song_data !== false) {
			$widget = "$before_widget $before_title $last_fm_title $after_title %s $after_widget";
			ob_start();

			if(!empty($song_data["image"])) { ?>
				<a href="<?=$song_data["url"]?>">
					<img src="<?=$song_data["image"]?>" alt="Cover art for <?=$song_data["image"]?>">
				</a>

				<p>Dash <?=$song_data["now_playing"] ? "is listening" : "listened"?>
					to <a href="<?=$song_data["url"]?>"><?=$song_data["song"]?></a>
					by <a href="http://www.last.fm/music/<?=urlencode($song_data["artist"])?>"><?=$song_data["artist"]?></a>
					from <a href="http://www.last.fm/music/<?=urlencode($song_data["artist"])?>/<?=urlencode($song_data["album"])?>"><?=$song_data["album"]?></a>
					<?=$song_data["now_playing"] ? "right now!" : "on ".$song_data["date"]."."; ?></p>
			<? } else { ?>
				<img src="<?= plugin_dir_url(__FILE__) . "cd.png" ?>" alt="a compact disc" class="unknown-album">

				<p>Dash <?=$song_data["now_playing"]? "is listening" : "listened"?> to
					<?=$song_data["song"]?> by <?=$song_data["artist"] ?>
					<?=$song_data["now_playing"] ? "right now!" : "on " . $song_data["date"]. "."; ?></p>
			<? }

			$display = ob_get_clean();
			$widget  = sprintf($widget, $display);
		}

		echo $widget;
	}

	public function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance["last_fm_title"]   = sanitize_text_field($new_instance["last_fm_title"]);
		$instance["last_fm_user"]    = sanitize_text_field($new_instance["last_fm_user"]);
		$instance["last_fm_api_key"] = sanitize_text_field($new_instance["last_fm_api_key"]);
		return $instance;
	}

	public function form($instance) {
		$defaults = array(
			"last_fm_title"   => "",
			"last_fm_user"    => "",
			"last_fm_api_key" => "",
		);

		$instance = wp_parse_args((array) $instance, $defaults); ?>

		<p>
			<label for="<?php echo $this->get_field_id("last_fm_title"); ?>">Widget's Title:</label>
			<input id="<?php echo $this->get_field_id("last_fm_title"); ?>" name="<?php echo $this->get_field_name("last_fm_title"); ?>" value="<?php echo $instance["last_fm_title"]; ?>" type="text" class="widefat">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id("last_fm_user"); ?>">Last.fm Username:</label>
			<input id="<?php echo $this->get_field_id("last_fm_user"); ?>" name="<?php echo $this->get_field_name("last_fm_user"); ?>" value="<?php echo $instance["last_fm_user"]; ?>" type="text" class="widefat">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id("last_fm_api_key"); ?>">Last.fm API Key:</label>
			<input id="<?php echo $this->get_field_id("last_fm_api_key"); ?>" name="<?php echo $this->get_field_name("last_fm_api_key"); ?>" value="<?php echo $instance["last_fm_api_key"]; ?>" type="text" class="widefat">
		</p>

	<?php }

	protected function get_recent_song_from_last_fm($last_fm_user, $last_fm_api_key) {

		// to help avoid hitting last.fm too rapidly, we cache the results of our most recent
		// song for five minutes.  if the following transient exists, we use it.  otherwise, we
		// fetch a new song.  it's possible that we miss a tune, since they're often less than
		// five minutes long, but that's not the end of the world.

		$song_data = get_transient("dashifen_last_fm_recent_song_data");
		if ($song_data === false) {
			$request = new WP_Http();
			$isXML = false;
			$tries = 0;

			while(!$isXML) {
				try {
					// we're going to try and get our XML 5 times.  since the SimpleXMLElement will toss an
					// exception if we couldn't get XML from last.fm, then we'll end up in our catch block where
					// we increment the $tries variable and sleep for a second.  but, if no exception is thrown,
					// then we set the $isXML flag and the while loop ends.

					$url = "http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=%s&limit=1&api_key=%s";
					$url = sprintf($url, $last_fm_user, $last_fm_api_key);

					$results = $request->request($url);
					$xml = new SimpleXMLElement($results["body"]);
					$track = $xml->recenttracks->track;
					$song_data = array();
					$isXML = true;

					if(isset($track->date)) {
						$song_data["now_playing"] = false;
						$timezone  = date("I")==1 ? "-5 hours" : "-6 hours";
						$song_data["date"] = date("n/j/Y \a\\t g:iA", strtotime($timezone, strtotime($track->date)));
					} else $song_data["now_playing"] = true;

					$song_data["url"]    = (string) $track->url;
					$song_data["song"]   = (string) $track->name;
					$song_data["image"]  = (string) $track->image[3];		// get the extra large size
					$song_data["artist"] = (string) $track->artist;
					$song_data["album"]  = (string) $track->album;

					// now that we've initialized the song data, we'll save it in our transient.
					// that way we can re-use it within the next five minutes.

					set_transient("dashifen_last_fm_recent_song_data", $song_data, HOUR_IN_SECONDS / 12);

				} catch (Exception $e) {
					// here in the catch block we want to increment tries.  once we've tried 5 times, we make
					// our $isXML flag true to get out of the while loop and give up by showing what ever we showed
					// last time it worked.

					if(++$tries == 5) $isXML = true;
				}
			}

			if (!$isXML) {
				$song_data = false;
			}
		}

		return $song_data;
	}
}

function load_dashifen_last_fm_widget() {
	register_widget("dashifen_last_fm_widget");
}

add_action("widgets_init", "load_dashifen_last_fm_widget");

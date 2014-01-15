=== Post to Queue ===
Contributors: dimadin
Donate link: http://blog.milandinic.com/donate/
Tags: 
Requires at least: 3.5
Tested up to: 3.8
Stable tag: 1.0

Stack posts to queue and auto publish them in chosen interval and time frame.

== Description ==

[Plugin homepage](http://blog.milandinic.com/wordpress/plugins/post-to-queue/) | [Plugin author](http://blog.milandinic.com/) | [Donate](http://blog.milandinic.com/donate/)

Don't want to publish all of your posts at once but hate manual scheduling/rescheduling? Post to Queue comes as a solution. You just put posts to queue and they'll be published automatically when chosen time passes since last published post. It's even possible to choose days of the week and hours of the day when those posts will be published.

Post to Queue is like Buffer, just better.

It requires that cron runs regularly to be able to publish posts on time.

Post to Queue code is partly based on a code from plugin [Automatic Post Scheduler](http://wordpress.org/plugins/automatic-post-scheduler/) by [Tudor Sandu](http://tudorsandu.ro/) and a code from plugin [Metronet Reorder Posts](http://wordpress.org/plugins/metronet-reorder-posts/) by [Ronald Huereca](http://www.ronalfy.com/) and [Ryan Hellyer](http://geek.ryanhellyer.net/) for [Metronet Norge AS](http://www.metronet.no/).

== Installation ==

= From your WordPress dashboard =

1. Visit 'Plugins > Add New'
2. Search for 'Post to Queue'
3. Activate Post to Queue' from your Plugins page.
4. Write post, check 'Add to queue' and press 'Publish' to queue post.

= From WordPress.org =

1. Download Post to Queue'.
2. Upload the 'post-to-queue' directory to your '/wp-content/plugins/' directory, using your favorite method (ftp, sftp, scp, etc...)
3. Activate Post to Queue' from your Plugins page. (You'll be greeted with a Welcome page.)
4. Write post, check 'Add to queue' and press 'Publish' to queue post.

= Extra =

Visit 'Settings > Writing' and adjust your configuration.

== Frequently Asked Questions ==

= My post was published immediately after I queued it. Why? =

This means that time between your last published post and time you queued new post was longer than queue interval.

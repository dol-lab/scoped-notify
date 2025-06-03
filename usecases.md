# Scoped Notify implementation

Spaces can be public, private

## Use cases

### default behaviour:

- no entries in any tables (user has not made any changes to their setting)
- a post is published in a space they are subscribed to - they get notified
- a comment is published in a space they are subscribed to - they get notified
- they do not get notified for any posts/comments in spaces they are not subscribed to

implementation:
	- someone publishes post/page/comment
	- hook: SN queue entries are created for each subscriber (subscribers are a combination of members and non-members which have notifications set to true for this space)
	- question: should queue be filled with already checked users or should the specific user settings influencing if a notification is sent be checked afterwards?

### case 1: a lengthy discussion erupts under a post in which the user is not interested at all

- solution: mute comments for this post
- implementation:
  * create mute entry in sn_scoped_settings_post_comments referencing the post-comment trigger (db design here violating daniel's law make invalid state unrepresentable)

### case 2: user is a member of a very comment happy space but is not interested in the discussions, just the posts

- solution: set blog setting to "notify post only"
- implementation: create mute entry in sn_scoped_settings_blogs referencing the post-comment trigger


#### case 2a: user wants comments for one specific post

- solution: unmute comments for this post
- implementation: create unmute entry in sn_scoped_settings_post_comments referencing the post-comment trigger (db design here violating daniel's law make invalid state unrepresentable)

### case 3: user takes a sabbatical or is in highly stressful examination phase and wants to temporarily disable all notifications (while keeping the specific settings)

- solution (ugly): the user has to disable notifications for every space and mute comments for every post which had explicit unmute.
there is a network wide table but we have the rule "most specific wins". so any setting in the network wide table has no effect at all if the user has made explicit unmute settings in any other table. so what is this table for?

### case 4: a space is invite only but the user is interested in its posts and comments

- solution: turn on notifications for posts or posts/comments as non-member
- implementation: create explicit unmute entries in sn_scoped_settings_blogs referencing the post-comment and post-post triggers

### case 5: user is not interested in mail notifications at all, they want a setting where they do not have to turn off the (sent by default) notifications every time they become member of a new space

- solution: use "global notification" toggle in user profile (set by default to "notify") and turn off notification in user profile (which has no effect on past settings, see above)
- implementation: create mute entry in sn_scoped_settings_network_users referencing the post-post and post-comment triggers

## Proposed UI

### user profile page

radionbuttons in user profile for network wide setting with options
```
mail notifications for posts and comments	()
mail notifications for posts only			()
no notifications							()
hint: does not override existing settings for spaces and posts
```

### card components on user profile page

radiobuttons in the three-dot menu of all the cards of spaces and subscriptions with options
```
mail notifications for posts and comments	()
mail notifications for posts only			()
no notifications							()
hint: does not override existing settings for comments in posts
```

mail icon in user profile list of cards as indicator if notifications are on (missing if notification are disabled)

### main page of space

radiobuttons in space settings with options
```
mail notifications for posts and comments	()
mail notifications for posts only			()
no notifications							()
hint: does not override existing settings for comments in posts
```

**implementation**
* value is set to "mail notifications for posts and comments" per default if user is subscribed
* value is set to "no notification" per default if user is not subscribed
* value is set to user setting if user has entries in sn

### post component

radiobuttons in three-dot-menu
```
mail notifications for comments		()
no mail notifications for comments	()
use default settings for this space	()
```

problem with the strike-through mail icon which signals that for this post no notification was sent by decision of the author (or space owner?)

## Things to check

* checks for actually sending notifications:
	* should subscribers (non-members) get notifications for private posts? (scoped notify currently uses the "save_post" action hook)

* permissions: is the user allowed to change a space-specific setting?
	* is user member of space? yes
	* if user is not a member, is the space public? yes

* what happens to user settings if a user is kicked out of a space by a space  admin? does it depend on the space visibility?

* what happens to non-member subscriptions if a space is switched to "visible to members only"?
  * current state: existing subscribers can still view posts in this space, so it is probably not necessary to delete their notification settings. space administrators can remove unwanted subscribers manually.

* check how the toggling of the setting "Benachrichtige alle Mitglieder" in spaces-editor works together with scoped notify

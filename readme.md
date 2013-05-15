# LODLAM User Import

A basic plugin for importing users from a CSV file into a BuddyPress installation. Features:

- Creates the relevante BP profile fields when they don't yet exist
- Does not attempt to create new users when an email is matched in the db, but does update profile fields for existing users
- Two-stage import process that provides a preview of profile data that will be imported before actually running

This plugin is *not* usable out of the box. You will have to map it to the correct columns in your CSV and profile fields in your BP installation. But this should get you most of the way there.

Note that the plugin expects a users.csv file in the plugin directory as a source. You'll have to put it there manually.

Originally created for lodlam.net. Thanks to them for supporting its development.

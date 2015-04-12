# FTL
PHP based time machine

Single PHP script that provides a simple dashboard for making backups of your website. Pretty much obsolete when you already use Git.

Essentially creates a ZIP archive of your files (every day, week or month), with the option to exclude certain files. FTL will keep track of the backups, and starts deleting the oldest copies when they exceed a predefined amount of space. Also allow you to download or restore to an earlier copy.

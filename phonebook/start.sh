#!/bin/sh

echo -n "Webtop to Phonebook sync "
if  ! php /usr/share/phonebooks/scripts/webtop2phonebook.php; then
	echo "fail!"
else
	echo "success!"
fi

echo -n "Phonebook to WebTop sync "
if ! php /usr/share/phonebooks/post_scripts/pbook2webtop.php; then
	echo "fail!"
else
	echo "success!"
fi

#!/usr/bin/python
# -*- coding: utf-8 -*-
import os,time
import sys
import sqlite3
import mutagen
import sqlite3 as lite

startTime = time.time()
fileList = []
rootdir = sys.argv[1]
try:
    con = lite.connect('db/cogimix.db')
    cur = con.cursor() 
    cur.execute("""
         CREATE TABLE IF NOT EXISTS tracks (
        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL ,
        title varchar(255) DEFAULT NULL,
        artist varchar(255) DEFAULT NULL,
        album varchar(255) DEFAULT NULL,
        filepath text)
        """)
    
    insert = []
    count = 0
    for root, subFolders, files in os.walk(rootdir):
    	for file in files:
    	    try:	    
    	      filepath = os.path.join(root,file)
    	      #print filepath
              audio = mutagen.File(filepath,easy=True);
              if audio:
                  artist = audio.get("artist")
                  if artist and len(artist) >0 : artist = artist[0]
                  title = audio.get("title")
                  if title and len(title) > 0 : title = title[0]
                  album = audio.get("album")
                  if album and len(album) >0 : album = album[0]
                  i = (title,artist,album,filepath.decode('utf-8'))
                  insert+= [i]
                  count+=1
                  sys.stdout.write("\r%d" % count)
                  sys.stdout.flush()
            except UnicodeDecodeError:
              pass
       
            if (count % 200 == 0) :
              cur.executemany("INSERT INTO tracks(title,artist,album,filepath) VALUES (?, ?, ?, ?)", insert)
              con.commit()
              insert = []
    #print insert
    cur.executemany("INSERT INTO tracks(title,artist,album,filepath) VALUES (?, ?, ?, ?)", insert)   
    con.commit()     
    
except lite.Error, e:
    print "\nError %s:" % e.args[0]
    sys.exit(1)
    
finally:
    if con:
        con.close()
endTime = time.time()
print "\n", count,"entries inserted in",endTime-startTime,"s"


	
	

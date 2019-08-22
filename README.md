exist-db for GHDI
=================

Installation
------------
Adjust Settings

- vi config/parameters.yaml

Directory Permissions for cache and logs

- sudo setfacl -R -m u:www-data:rwX ./var
- sudo setfacl -dR -m u:www-data:rwX ./var

## Repository Structure

Guidelines http://exist-db.org/exist/apps/doc/using-collections.xml

Settings

* Root-collection: ./bin/console existdb:import base (creates /db/apps/ghdi/data)


### Sub-collections and Resources

#### Authority Data
Located in /db/apps/ghdi/data/authority/{persons|organizations|places}

#### Volumes
Located in /db/apps/ghdi/data/volumes/

* Sub-Collection: /db/apps/ghdi/data/volumes/volume-13
  Resource: volume-13.{deu|eng}.xml
  <idno type="DTAID">ghdi/007:volume-13/</idno>
  <idno type="DTADirName">nazi-germany-1933-1945</idno>
* Sub-Collection: /db/apps/ghdi/data/volumes/volume-13/introduction
  Introduction: introduction-93.{deu|eng}.xml
  <idno type="DTAID">ghdi/007:volume-13/introduction-93</idno>
  <idno type="DTADirName">overview</idno>
* Sub-Collection: /db/apps/ghdi/data/volumes/volume-13/documents
* Sub-Collection: /db/apps/ghdi/data/volumes/volume-13/images
* (Sub-Collection: /db/apps/ghdi/data/volumes/volume-13/audiovisual)
* Sub-Collection: /db/apps/ghdi/data/volumes/volume-13/maps

## Add indexes

    ./bin/console existdb:index volumes
    ./bin/console existdb:index persons
    ./bin/console existdb:index organizations
    ./bin/console existdb:index places

## Import

    ./bin/console existdb:import volumes volume-15.deu.xml
    ./bin/console existdb:import volumes volume-15.eng.xml

    #!/bin/bash
    for filenameAbs in data/tei/introduction*.xml; do
        filename=$(basename -- "$filenameAbs")
        ./bin/console existdb:import volumes "$filename"
    done

### Add Test Authority Data

    ./bin/console existdb:import persons
    ./bin/console existdb:import organizations
    ./bin/console existdb:import places


### Questions

* How do we call the main parts of GHDI? Answer: Volume

Development Notes
-----------------

Translate routes

    ./bin/console translation:extract de --dir=./src/ --dir=./templates/ --output-dir=./translations --enable-extractor=jms_i18n_routing

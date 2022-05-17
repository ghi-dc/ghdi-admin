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

## Add to Frontend (Solr and TEI files)

    ./bin/console solr:populate --locale=en volume-XX
    ./bin/console solr:populate --locale=de volume-XX

## Fetch Frontend Bibliography

    ./bin/console zotero:fetch-collection volume-3 4CXHVSIY

## Scalar (Legacy)

### Setup of the Scalar instance

    # create database
    mysqladmin -u root create scalar_ghdi_de

    # import table structure

    # scalar_store.sql is in system/application/config/scalar_store.sql (standard MySQL 3-byte UTF-8)
    # or use system/application/config/scalar_store_utf8mb4.sql (extended 4-byte UTF-8 support)
    mysql -u root scalar_ghdi_de < scalar_store_utf8mb4.sql

    # register admin-user through web-site
    # then - in mysql
    UPDATE scalar_db_users SET is_super = 1 WHERE email='admin@email';

    # Sign out and sign back in as admin@email for the new privs to be active

    # Add an API User (api@email)

    # Through the dashboard: [All users] create a new User for the book in question
    # Through the dashboard: [All books] create a new book with said user as Initial Author and API User
    # Important: Make book public

    # Now set api_key (ATTENTION, only works for non super users)
    # SELECT user_id INTO @user_id FROM scalar_db_users WHERE email='api@email';
    # UPDATE scalar_db_user_books SET api_key = SHA1('api_key')
    # WHERE user_id=@user_id AND api_key IS NULL;

### Prepare the import from admin

in config/parameters.yml, set URLs of the Scalar and the admin site, the book info and the
API user

    app.site.base_uri: http://localhost/ghdi/admin/
    app.site.auth_basic: 'guest:guest'

    app.scalar_client.options:
        baseurl: http://localhost/ghdi/scalar/
        id: api@email
        # for api_key to work, we need to set SET api_key = SHA1(..)
        # for every book individually in scalar_db_user_books
        # ATTENTION, only works for non super!
        #
        # SELECT user_id  INTO @user_id FROM scalar_db_users WHERE email='VALUE OF app.scalar.client.id';
        # UPDATE scalar_db_user_books SET api_key = SHA1('VALUE OF app.scalar.api_key') WHERE user_id=@user_id AND api_key IS NULL;
        #
        # ATTENTION, book must be set to public for api to work
        api_key: 'api_key'
        book: 'from-vormaerz-to-prussian-dominance-1815-1866'

### Content import

    ./bin/console scalar:import --volume=15 --locale=de introduction
    ./bin/console scalar:import --volume=15 --locale=de documents
    ./bin/console scalar:import --volume=15 --locale=de images
    ./bin/console scalar:import --volume=15 --locale=de maps

or for individual resources
    ./bin/console scalar:import --volume=15 --locale=de document-1234

### Paths

    ./bin/console scalar:import --volume=15 --locale=de index-path
    ./bin/console scalar:import --volume=15 --locale=de introduction-path
    ./bin/console scalar:import --volume=15 --locale=de document-path
    ./bin/console scalar:import --volume=15 --locale=de image-path
    ./bin/console scalar:import --volume=15 --locale=de map-path

## Questions

* How do we call the main parts of GHDI? Answer: Volume

Development Notes
-----------------

Translate routes

    ./bin/console translation:extract de --dir=./src/ --dir=./templates/ --output-dir=./translations

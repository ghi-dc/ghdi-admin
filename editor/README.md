Prosemirror-based Editor for GHDI
=================================

Build
-----
Build is based on https://webpack.js.org/

In `PROJECT_ROOT/editor`:

    $ ./node_modules/.bin/webpack

in order to build `PROJECT_ROOT/public/js/editor-bundle.js`.

after having installed all dependencies by running

    $ npm init
    $ npm install prosemirror-inputrules prosemirror-schema-basic prosemirror-schema-list prosemirror-keymap prosemirror-history prosemirror-commands prosemirror-state prosemirror-menu prosemirror-dropcursor prosemirror-gapcursor

Webpack installation
--------------------
See https://webpack.js.org/guides/installation/

Make sure you have `npm` installed as described in https://www.npmjs.com/get-npm

In `PROJECT_ROOT/editor`:

    $ npm install --save-dev webpack webpack-cli


Project structure
-----------------
The wepack setup is based on https://github.com/buzz-software/prosemirror-webpack-project

`src/prosemirror-setup` is a slightly tweaked copy of https://github.com/ProseMirror/prosemirror-example-setup

In addition to include `inline-editor-bundle.js`, you need some css for the view and the menu. We therefore put copies of
https://github.com/ProseMirror/prosemirror-view/blob/master/style/prosemirror.css
and https://github.com/ProseMirror/prosemirror-menu/blob/master/style/menu.css and https://github.com/ProseMirror/prosemirror-example-setup/blob/master/style/style.css
into `public/css/inline-editor-bundle` as part of the webpack build.
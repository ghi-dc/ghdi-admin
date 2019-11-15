/**
 * This is a heavily customized version of
 * https://github.com/ProseMirror/prosemirror-example-setup/blob/master/src/menu.js
 */
import {wrapItem, blockTypeItem, Dropdown, DropdownSubmenu, joinUpItem, liftItem,
       selectParentNodeItem, undoItem, redoItem, icons, MenuItem} from "prosemirror-menu"
import {NodeSelection} from "prosemirror-state"
import {toggleMark} from "prosemirror-commands"
import {findWrapping,insertPoint} from "prosemirror-transform"
import {Fragment} from "prosemirror-model"
import {wrapInList} from "prosemirror-schema-list"
import {TextField, openPrompt} from "./prompt"


// Helpers to create specific types of items

function canInsert(state, nodeType) {
  let $from = state.selection.$from
  for (let d = $from.depth; d >= 0; d--) {
    let index = $from.index(d)
    if ($from.node(d).canReplaceWith(index, index, nodeType)) return true
  }
  return false
}

function insertImageItem(nodeType) {
  return new MenuItem({
    title: "Insert image",
    label: "Image",
    enable(state) { return canInsert(state, nodeType) },
    run(state, _, view) {
      let {from, to} = state.selection, attrs = null
      if (state.selection instanceof NodeSelection && state.selection.node.type == nodeType)
        attrs = state.selection.node.attrs
      openPrompt({
        title: "Insert image",
        fields: {
          src: new TextField({label: "Location", required: true, value: attrs && attrs.src}),
          title: new TextField({label: "Title", value: attrs && attrs.title}),
          alt: new TextField({label: "Description",
                              value: attrs ? attrs.alt : state.doc.textBetween(from, to, " ")})
        },
        callback(attrs) {
          view.dispatch(view.state.tr.replaceSelectionWith(nodeType.createAndFill(attrs)))
          view.focus()
        }
      })
    }
  })
}

function cmdItem(cmd, options) {
  let passedOptions = {
    label: options.title,
    run: cmd
  }
  for (let prop in options) passedOptions[prop] = options[prop]
  if ((!options.enable || options.enable === true) && !options.select)
    passedOptions[options.enable ? "enable" : "select"] = state => cmd(state)

  return new MenuItem(passedOptions)
}

function markActive(state, type) {
  let {from, $from, to, empty} = state.selection
  if (empty) return type.isInSet(state.storedMarks || $from.marks())
  else return state.doc.rangeHasMark(from, to, type)
}

function markItem(markType, options) {
  let passedOptions = {
    active(state) { return markActive(state, markType) },
    enable: true
  }
  for (let prop in options) passedOptions[prop] = options[prop]
  return cmdItem(toggleMark(markType), passedOptions)
}

// see https://github.com/scrumpy/tiptap/blob/21c8ad852a8cf6602153948ffe40415794a318a4/packages/tiptap-utils/src/utils/getMarkAttrs.js
function getMarkAttrs (state, type) {
  const { from, to } = state.selection
  let marks = []

  state.doc.nodesBetween(from, to, node => {
    marks = [...marks, ...node.marks]
  })

  const mark = marks.find(markItem => markItem.type.name === type.name)

  if (mark) {
    return mark.attrs
  }

  return {}
}

// see https://github.com/scrumpy/tiptap/issues/490
// for ideas how to improve
function linkItem(markType) {
  return new MenuItem({
    title: "Add or remove link",
    icon:
      { text: '\uf0c1', 'css': 'font-family: FontAwesome' },
      // icons.link,
    active(state) { return markActive(state, markType) },
    enable(state) { return !state.selection.empty },
    run(state, dispatch, view) {
      if (markActive(state, markType)) {
        toggleMark(markType)(state, dispatch)
        // return true
      }
      const attrs = getMarkAttrs(state, markType)
      openPrompt({
        title: "Create a link",
        fields: {
          href: new TextField({
            label: "Link target",
            required: true,
            value: attrs.href
          }),
          // title: new TextField({label: "Title"})
        },
        callback(attrs) {
          toggleMark(markType, attrs)(view.state, view.dispatch)
          view.focus()
        }
      })
    }
  })
}

function linkPersName(markType) {
  return new MenuItem({
    title: "Add or remove persName",
    icon:
      { text: '\uf007', 'css': 'font-family: FontAwesome' },
      // icons.link,
    active(state) { return markActive(state, markType) },
    enable(state) { return !state.selection.empty },
    run(state, dispatch, view) {
      if (markActive(state, markType)) {
        toggleMark(markType)(state, dispatch)
        // return true
      }
      const attrs = getMarkAttrs(state, markType)
      openPrompt({
        title: "Link a person",
        fields: {
          ref: new TextField({
            label: "Person reference",
            required: true,
            value: attrs.ref
          }),
          // title: new TextField({label: "Title"})
        },
        callback(attrs) {
          toggleMark(markType, attrs)(view.state, view.dispatch)
          view.focus()
        }
      })
    }
  })
}

// TODO: share with linkPersName
function linkOrgName(markType) {
  return new MenuItem({
    title: "Add or remove orgName",
    icon:
      { text: '\uf275', 'css': 'font-family: FontAwesome' },
      // icons.link,
    active(state) { return markActive(state, markType) },
    enable(state) { return !state.selection.empty },
    run(state, dispatch, view) {
      if (markActive(state, markType)) {
        toggleMark(markType)(state, dispatch)
        // return true
      }
      const attrs = getMarkAttrs(state, markType)
      openPrompt({
        title: "Link an organization",
        fields: {
          ref: new TextField({
            label: "Organization reference",
            required: true,
            value: attrs.ref
          }),
          // title: new TextField({label: "Title"})
        },
        callback(attrs) {
          toggleMark(markType, attrs)(view.state, view.dispatch)
          view.focus()
        }
      })
    }
  })
}

function wrapListItem(nodeType, options) {
  return cmdItem(wrapInList(nodeType, options.attrs), options)
}

function wrapInDiv(divType, attrs) {
  return function(state, dispatch) {
    // see https://prosemirror.net/examples/schema/
    // Get a range around the selected blocks
    let range = state.selection.$from.blockRange(state.selection.$to)
    // See if it is possible to wrap that range in a note group
    let wrapping = findWrapping(range, divType)
    // If not, the command doesn't apply
    if (!wrapping) return false
    // Otherwise, dispatch a transaction, using the `wrap` method to
    // create the step that does the actual wrapping.
    if (dispatch) dispatch(state.tr.wrap(range, wrapping).scrollIntoView())
    return true
  }
}

function wrapDivItem(nodeType, options) {
  return cmdItem(wrapInDiv(nodeType, options.attrs), options)
}

// :: (Schema) → Object
// Given a schema, look for default mark and node types in it and
// return an object with relevant menu items relating to those marks:
//
// **`toggleStrong`**`: MenuItem`
//   : A menu item to toggle the [strong mark](#schema-basic.StrongMark).
//
// **`toggleEm`**`: MenuItem`
//   : A menu item to toggle the [emphasis mark](#schema-basic.EmMark).
//
// **`toggleU`**`: MenuItem`
//   : A menu item to toggle the [code font mark](#schema-basic.UMark).
//
// **`toggleLink`**`: MenuItem`
//   : A menu item to toggle the [link mark](#schema-basic.LinkMark).
//
// **`insertImage`**`: MenuItem`
//   : A menu item to insert an [image](#schema-basic.Image).
//
// **`wrapBulletList`**`: MenuItem`
//   : A menu item to wrap the selection in a [bullet list](#schema-list.BulletList).
//
// **`wrapOrderedList`**`: MenuItem`
//   : A menu item to wrap the selection in an [ordered list](#schema-list.OrderedList).
//
// **`wrapBlockQuote`**`: MenuItem`
//   : A menu item to wrap the selection in a [block quote](#schema-basic.BlockQuote).
//
// **`makeParagraph`**`: MenuItem`
//   : A menu item to set the current textblock to be a normal
//     [paragraph](#schema-basic.Paragraph).
//
// **`makeCodeBlock`**`: MenuItem`
//   : A menu item to set the current textblock to be a
//     [code block](#schema-basic.CodeBlock).
//
// **`makeHead[N]`**`: MenuItem`
//   : Where _N_ is 1 to 6. Menu items to set the current textblock to
//     be a [heading](#schema-basic.Heading) of level _N_.
//
// **`insertHorizontalRule`**`: MenuItem`
//   : A menu item to insert a horizontal rule.
//
// The return value also contains some prefabricated menu elements and
// menus, that you can use instead of composing your own menu from
// scratch:
//
// **`insertMenu`**`: Dropdown`
//   : A dropdown containing the `insertImage` and
//     `insertHorizontalRule` items.
//
// **`typeMenu`**`: Dropdown`
//   : A dropdown containing the items for making the current
//     textblock a paragraph, code block, or heading.
//
// **`fullMenu`**`: [[MenuElement]]`
//   : An array of arrays of menu elements for use as the full menu
//     for, for example the [menu bar](https://github.com/prosemirror/prosemirror-menu#user-content-menubar).
export function buildMenuItems(schema, blockMenu = true) {
  let r = {}, type
  if (type = schema.marks.strong) {
    r.toggleStrong = markItem(type, {
      title: "Toggle strong style",
      icon:
        { text: "\uf032", 'css': 'font-family: FontAwesome' }
        // { text: 'B', 'css': 'font-weight: bold' }
        // icons.strong
    })
  }
  if (type = schema.marks.em) {
    r.toggleEm = markItem(type, {
      title: "Toggle emphasis",
      icon:
        { text: '\uf033', 'css': 'font-family: FontAwesome' }
        // { text: 'I', 'css': 'font-style: italic' }
        // icons.em
    })
  }
  if (type = schema.marks.u) {
    r.toggleU = markItem(type, {
      title: "Toggle underline",
      icon:
        { text: '\uf0cd', 'css': 'font-family: FontAwesome' }
        // { text: 'U', 'css': 'text-decoration: underline' }
    })
  }
  if (type = schema.marks.link)
    r.toggleLink = linkItem(type)
  if (type = schema.marks.persName)
    r.togglePersName = linkPersName(type)
  if (type = schema.marks.orgName)
    r.toggleOrgName = linkOrgName(type)

  if (type = schema.nodes.image)
    r.insertImage = insertImageItem(type)
  if (type = schema.nodes.bullet_list)
    r.wrapBulletList = wrapListItem(type, {
      title: "Wrap in bullet list",
      icon: icons.bulletList
    })
  if (type = schema.nodes.ordered_list)
    r.wrapOrderedList = wrapListItem(type, {
      title: "Wrap in ordered list",
      icon: icons.orderedList
    })
  if (type = schema.nodes.blockquote)
    r.wrapBlockQuote = wrapItem(type, {
      title: "Wrap in block quote",
      icon: icons.blockquote
    })
  if (type = schema.nodes.paragraph)
    r.makeParagraph = blockTypeItem(type, {
      title: "Change to paragraph",
      label: "Plain"
    })
  if (type = schema.nodes.code_block)
    r.makeCodeBlock = blockTypeItem(type, {
      title: "Change to code block",
      label: "Code"
    })
  if (type = schema.nodes.heading)
    for (let i = 2; i <= 2; i++)
      r["makeHead" + i] = blockTypeItem(type, {
        title: "Change to heading " + i,
        label: "Level " + i,
        attrs: {level: i}
      })
  // dbu
  if (type = schema.nodes.head)
    r.makeHead = blockTypeItem(type, {
      title: "Change to head",
      label: "Head"
    })

  if (type = schema.nodes.div) {
    r.wrapDiv = wrapDivItem(type, {
      title: "Wrap in sub-section",
      icon: icons.lift
    })
  }

  if (type = schema.nodes.footnote) {
    r.insertFootnote = new MenuItem({
      title: "Insert footnote",
      label: "Footnote",
      select(state) {
        return insertPoint(state.doc, state.selection.from, schema.nodes.footnote) != null
      },
      run(state, dispatch) {
        let {empty, $from, $to} = state.selection, content = Fragment.empty
        if (!empty && $from.sameParent($to) && $from.parent.inlineContent)
          content = $from.parent.content.cut($from.parentOffset, $to.parentOffset)
        dispatch(state.tr.replaceSelectionWith(schema.nodes.footnote.create(null, content)))
      }
    })
  }

  if (type = schema.nodes.horizontal_rule) {
    let hr = type
    r.insertHorizontalRule = new MenuItem({
      title: "Insert horizontal rule",
      label: "Horizontal rule",
      enable(state) { return canInsert(state, hr) },
      run(state, dispatch) { dispatch(state.tr.replaceSelectionWith(hr.create())) }
    })
  }

  let cut = arr => arr.filter(x => x)

  let insertItems = cut([r.insertImage, r.insertFootnote, r.insertHorizontalRule])
  r.insertMenu = blockMenu && insertItems.length > 0
    ? new Dropdown(insertItems, {label: "Insert"})
    : null

  let typeItems = cut([
    r.makeParagraph,
    /* r.makeCodeBlock, */
    r.makeHead,
    r.makeHead2 && new DropdownSubmenu(cut([
      /* r.makeHead1, */ r.makeHead2, // r.makeHead3, r.makeHead4, r.makeHead5, r.makeHead6
    ]), {label: "Heading"})
  ])
  r.typeMenu = blockMenu && typeItems.length > 0
    ? new Dropdown(typeItems, {label: "Type..."})
    : null

  r.inlineMenu = [cut([r.toggleStrong, r.toggleEm, r.toggleU, r.toggleLink, r.togglePersName, r.toggleOrgName ])]
  r.blockMenu = blockMenu
    ? [cut([r.wrapBulletList, r.wrapOrderedList, r.wrapBlockQuote, r.wrapDiv, joinUpItem,
                      liftItem, selectParentNodeItem])]
    : []
  r.fullMenu = r.inlineMenu.concat([cut([r.insertMenu, r.typeMenu])], blockMenu ? [[undoItem, redoItem]] : [], r.blockMenu)

  return r
}

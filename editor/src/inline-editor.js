import {EditorState} from "prosemirror-state"
import {EditorView} from "prosemirror-view"
import {Schema, DOMParser, DOMSerializer} from "prosemirror-model"
// import {schema} from "prosemirror-schema-basic"
import {addListNodes} from "prosemirror-schema-list"
import {editorSetup,buildMenuItems} from "./prosemirror-setup/index.js"
import {selectionMenu} from "./prosemirror-setup/selection-menu.js"

/*
// Mix the nodes from prosemirror-schema-list into the basic schema to
// create a schema with list support.
const blockSchema = new Schema({
  nodes: addListNodes(schema.spec.nodes, "paragraph block*", "block"),
  marks: schema.spec.marks
})
*/

const dtabNodesBlock =
{
  doc: {
    content: "head? block+"
  },
  // :: NodeSpec A plain paragraph textblock. Represented in the DOM
  // as a `<p>` element.
  paragraph: {
    content: "inline*",
    group: "block",
    parseDOM: [{tag: "p"}],
    toDOM() { return ["p", 0] }
  },

  div: {
    content: "head? block+",
    group: "block",
    parseDOM: [{tag: "div"}],
    toDOM() { return ["div", 0] }
  },

  head: {
    content: "inline*",
    parseDOM: [{tag: "head"}],
    toDOM() { return ["head", 0] }
  },

  // :: NodeSpec The text node.
  text: {
    group: "inline"
  },

  // :: NodeSpec A hard line break, represented in the DOM as `<br>`.
  hard_break: {
    inline: true,
    group: "inline",
    selectable: false,
    parseDOM: [{tag: "lb"}],
    toDOM() { return ["lb"] }
  },

  footnote: {
    group: "inline",
    content: "paragraph+", // was: "text*", see https://discuss.prosemirror.net/t/how-to-insert-linebreaks-and-formatting-in-footnotes/1828/3?u=burki
    inline: true,
    draggable: false, // changed from true
    // This makes the view treat the node as a leaf, even though it
    // technically has content
    atom: true, // seems to be needed to toggle display, https://prosemirror.net/docs/ref/#model.NodeSpec.atom
    toDOM: () => ["note", 0],
    parseDOM: [{tag: "note"}]
  }
}

const dtabfMarks = {
  // parseDom for hi with rendition is inspired by https://prosemirror.net/examples/dino/
  // TODO: share among the different marks
  // TODO: add #g and maybe others
  strong: {
    toDOM() {
      return ["hi", { "rendition": "#b" }]
    },
    parseDOM: [{
      tag: "hi[rendition='#b']",
      getAttrs: dom => {
        let rend = dom.getAttribute("rendition")
        return rend ? { 'rendition' : '#b' } : false
      }
    }]
  },
  em: {
    toDOM() {
      return ["hi", { "rendition": "#i" }]
    },
    parseDOM: [{
      tag: "hi[rendition='#i']",
      getAttrs: dom => {
        let rend = dom.getAttribute("rendition")
        return rend ? { 'rendition' : '#i' } : false
      }
    }]
  },
  u: {
    toDOM() {
      return ["hi", { "rendition": "#u" }]
    },
    parseDOM: [{
      tag: "hi[rendition='#u']",
      getAttrs: dom => {
        let rend = dom.getAttribute("rendition")
        return rend ? { 'rendition' : '#u' } : false
      }
    }]
  },
  link: {
    attrs: {href: {}},
    toDOM(node) { return ["ref", {target: node.attrs.href}] },
    parseDOM: [{tag: "ref", getAttrs(dom) { return {href: dom.getAttribute("target")} }}],
    inclusive: false
  },
  persName: {
    attrs: {ref: {}},
    toDOM(node) { return ["persName", {target: node.attrs.ref}] },
    parseDOM: [{tag: "persName", getAttrs(dom) { return {ref: dom.getAttribute("ref")} }}],
    inclusive: false
  },
  orgName: {
    attrs: {ref: {}},
    toDOM(node) { return ["orgName", {target: node.attrs.ref}] },
    parseDOM: [{tag: "orgName", getAttrs(dom) { return {ref: dom.getAttribute("ref")} }}],
    inclusive: false
  }
  // TODO: maybe add date
}

const blockSchema = new Schema({
  nodes: dtabNodesBlock,
  marks: dtabfMarks
})

const inlineSchema = new Schema({
  nodes: {
    text: {},
    doc: {content: "text*"}
  },
  marks: dtabfMarks
})

let activeSchema = inlineSchema;

window.serializer = DOMSerializer.fromSchema(activeSchema);

window.createEditorView = function(editorSelector, contentString) {
  // since parse wants a domNode,
  // see https://discuss.prosemirror.net/t/documentation-how-to-serialize-unserialize-content-to-prosemirror-instance/366/6
  let domNode = document.createElement("div");
  domNode.innerHTML = contentString;

  return new EditorView(document.querySelector(editorSelector), {
    state: EditorState.create({
      doc: DOMParser.fromSchema(activeSchema).parse(domNode),
      plugins: editorSetup({
        schema: activeSchema,
        menu: selectionMenu({
          content: buildMenuItems(activeSchema, false).fullMenu,
        })
      })
    })
  });
}

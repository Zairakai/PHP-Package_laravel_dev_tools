// Generates what is not written by hand, so that it cannot drift from the code:
//   docs/reference/        the public API: one page per class, interface, trait and enum, read from src/
//   docs/guide/readme.md   the guide, copied from the README without its badges
// The PHP is parsed here (php-parser): no PHP and no Composer needed to build the site.
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Engine } from 'php-parser'

const here = dirname(fileURLToPath(import.meta.url))
const docs = resolve(here, '..')
const root = resolve(docs, '..')
const composer = JSON.parse(readFileSync(join(root, 'composer.json'), 'utf8'))
const repository = (process.env.DOCS_REPOSITORY_URL ?? composer.support?.source ?? '').replace(/\.git$/, '').replace(/\/$/, '')
const blob = process.env.DOCS_SOURCE_REF ?? 'main'
// GitLab puts a /-/ before blob, GitHub does not.
const blobPath = repository.includes('gitlab') ? '/-/blob/' : '/blob/'

const parser = new Engine({ parser: { php8: true, extractDoc: true, suppressErrors: true }, ast: { withPositions: true } })

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)

    return statSync(path).isDirectory() ? walk(path) : path.endsWith('.php') ? [path] : []
  })

// A docblock as { summary, description, tags }.
function doc(node) {
  const comment = (node.leadingComments ?? []).filter((item) => item.kind === 'commentblock' && item.value.startsWith('/**')).at(-1)

  if (!comment) {
    return { summary: '', description: '', tags: [] }
  }

  const lines = comment.value
    .replace(/^\/\*\*|\*\/$/g, '')
    .split('\n')
    .map((line) => line.replace(/^\s*\* ?/, ''))
  const tags = []
  const text = []
  let current = null

  for (const line of lines) {
    const tag = /^@(\w+)\s*(.*)$/.exec(line)

    if (tag) {
      current = { name: tag[1], text: tag[2] }
      tags.push(current)
    } else if (current && '' !== line.trim()) {
      current.text += ` ${line.trim()}`
    } else if (!current) {
      text.push(line)
    }
  }

  const paragraphs = text.join('\n').trim().split(/\n\s*\n/)

  return { summary: paragraphs[0]?.replace(/\n/g, ' ') ?? '', description: paragraphs.slice(1).join('\n\n'), tags }
}

function type(node) {
  if (!node) {
    return ''
  }

  const nullable = node.nullable ? '?' : ''

  switch (node.kind) {
    case 'uniontype':
      return node.types.map(type).join('|')
    case 'intersectiontype':
      return node.types.map(type).join('&')
    case 'typereference':
    case 'name':
      return nullable + String(node.raw ?? node.name)
    default:
      return nullable + String(node.name ?? node.raw ?? '')
  }
}

function value(node) {
  if (!node) {
    return ''
  }

  switch (node.kind) {
    case 'boolean':
    case 'number':
      return String(node.raw ?? node.value)
    case 'string':
      return node.isDoubleQuote ? `"${node.value}"` : `'${node.value}'`
    case 'nullkeyword':
      return 'null'
    case 'array':
      return `[${node.items.map((item) => (item.kind === 'entry' ? `${item.key ? `${value(item.key)} => ` : ''}${value(item.value)}` : value(item))).join(', ')}]`
    case 'staticlookup':
      return `${type(node.what)}::${node.offset?.name ?? ''}`
    case 'name':
      return String(node.name)
    case 'new':
      return `new ${type(node.what)}(${(node.arguments ?? []).map(value).join(', ')})`
    default:
      return String(node.raw ?? node.name ?? '…')
  }
}

const visibility = (node) => (node.visibility === 'private' ? 'private' : node.visibility === 'protected' ? 'protected' : 'public')

function parameter(node) {
  const promoted = node.flags ? 'promoted ' : ''

  return {
    text: `${type(node.type) ? `${type(node.type)} ` : ''}${node.variadic ? '...' : ''}${node.byref ? '&' : ''}$${node.name.name ?? node.name}${node.value ? ` = ${value(node.value)}` : ''}`,
    name: String(node.name.name ?? node.name),
    promoted: Boolean(promoted),
  }
}

const classes = []

function collect(node, namespace, file) {
  if (['class', 'interface', 'trait', 'enum'].includes(node.kind)) {
    const name = String(node.name.name ?? node.name)
    const info = { kind: node.kind, name, namespace, file, doc: doc(node), final: Boolean(node.isFinal), abstract: Boolean(node.isAbstract), readonly: Boolean(node.isReadonly), extends: node.extends ? (Array.isArray(node.extends) ? node.extends.map(type).join(', ') : type(node.extends)) : '', implements: (node.implements ?? []).map(type), constants: [], methods: [], line: node.loc?.start.line }

    for (const member of node.body ?? []) {
      if (member.kind === 'classconstant' && visibility(member) !== 'private') {
        for (const constant of member.constants) {
          info.constants.push({ name: String(constant.name.name ?? constant.name), value: value(constant.value), doc: doc(member) })
        }
      } else if (member.kind === 'method' && visibility(member) !== 'private') {
        const mdoc = doc(member)
        const params = member.arguments.map(parameter)

        info.methods.push({
          name: String(member.name.name ?? member.name),
          visibility: visibility(member),
          static: Boolean(member.isStatic),
          abstract: Boolean(member.isAbstract),
          params,
          returns: type(member.type),
          doc: mdoc,
          line: member.loc?.start.line,
        })
      }
    }

    classes.push(info)
  } else if (node.kind === 'namespace') {
    for (const child of node.children ?? []) {
      collect(child, String(node.name), file)
    }
  }
}

rmSync(join(docs, 'reference'), { recursive: true, force: true })
mkdirSync(join(docs, 'reference'), { recursive: true })

const sources = existsSync(join(root, 'src')) ? walk(join(root, 'src')).sort() : []

for (const file of sources) {
  const ast = parser.parseCode(readFileSync(file, 'utf8'), file)

  for (const node of ast.children) {
    collect(node, '', file)
  }
}

const rel = (file) => file.slice(root.length + 1)
const escape = (text) => String(text).replace(/\|/g, '\\|').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\{/g, '&#123;').replace(/\}/g, '&#125;')
const code = (text) => String(text).replace(/\|/g, '\\|')

function tagParam(method, name) {
  const found = method.doc.tags.find((tag) => tag.name === 'param' && new RegExp(`\\$${name}\\b`).test(tag.text))

  return found ? found.text.replace(/^.*?\$\w+\s*/, '') : ''
}

const items = []

for (const info of classes.sort((a, b) => `${a.namespace}\\${a.name}`.localeCompare(`${b.namespace}\\${b.name}`))) {
  const full = info.namespace ? `${info.namespace}\\${info.name}` : info.name
  const lines = [`# ${info.name}`, '']
  const modifiers = [info.final ? 'final' : '', info.abstract ? 'abstract' : '', info.readonly ? 'readonly' : '', info.kind].filter(Boolean).join(' ')

  lines.push('```php', `${modifiers} ${full}${info.extends ? ` extends ${info.extends}` : ''}${info.implements.length ? ` implements ${info.implements.join(', ')}` : ''}`, '```', '')

  if (info.doc.summary) {
    lines.push(escape(info.doc.summary), '')
  }

  if (info.doc.description) {
    lines.push(info.doc.description.replace(/^( {4}.*)$/gm, '$1'), '')
  }

  if (repository) {
    lines.push(`Source: [${rel(info.file)}](${repository}${blobPath}${blob}/${rel(info.file)}#L${info.line ?? 1})`, '')
  }

  if (info.constants.length) {
    lines.push('## Constants', '', '| Name | Value | Description |', '| :--- | :--- | :--- |')
    info.constants.forEach((constant) => lines.push(`| \`${constant.name}\` | \`${code(constant.value)}\` | ${escape(constant.doc.summary)} |`))
    lines.push('')
  }

  if (info.methods.length) {
    lines.push('## Methods', '')

    for (const method of info.methods) {
      const signature = `${method.visibility}${method.static ? ' static' : ''} function ${method.name}(${method.params.map((param) => param.text).join(', ')})${method.returns ? `: ${method.returns}` : ''}`

      lines.push(`### ${method.name}()`, '', '```php', signature, '```', '')

      if (method.doc.summary) {
        lines.push(escape(method.doc.summary), '')
      }

      if (method.params.some((param) => tagParam(method, param.name))) {
        lines.push('| Parameter | Description |', '| :--- | :--- |')
        method.params.forEach((param) => tagParam(method, param.name) && lines.push(`| \`$${param.name}\` | ${escape(tagParam(method, param.name))} |`))
        lines.push('')
      }

      for (const tag of method.doc.tags.filter((item) => ['return', 'throws'].includes(item.name))) {
        lines.push(`**${'return' === tag.name ? 'Returns' : 'Throws'}** ${escape(tag.text)}`, '')
      }
    }
  }

  // The constructor of a class with promoted properties documents them with @param: they are listed there.
  writeFileSync(join(docs, 'reference', `${info.name}.md`), lines.join('\n'))
  items.push({ text: info.name, link: `/reference/${info.name}` })
}

writeFileSync(
  join(docs, 'reference', 'index.md'),
  ['# Reference', '', `${items.length} classes, interfaces, traits and enums, read from \`src/\`.`, '', ...classes.map((info) => `- [${info.name}](/reference/${info.name})${info.doc.summary ? `: ${escape(info.doc.summary)}` : ''}`), ''].join('\n')
)
writeFileSync(join(docs, 'reference', 'sidebar.json'), JSON.stringify(items, null, 2))

// The guide: the README without its badges. Its relative links point at the code.
const readme = readFileSync(join(root, 'README.md'), 'utf8')
const absolute = (target) => {
  if (/^(https?:|mailto:|#|\/)/.test(target) || '' === repository) {
    return target
  }

  let path = target.replace(/^\.\//, '').replace(/#.*$/, '')

  if (!existsSync(join(root, path)) && existsSync(join(root, `${path}.md`))) {
    path = `${path}.md`
  }

  return `${repository}${blobPath}${blob}/${path}`
}

const guide = readme
  .split('\n')
  .filter((line) => !/^\s*\[!\[|^\s*!\[|^\[[^\]]+\]:\s+https?:.*(badge|shields|img)/i.test(line))
  .join('\n')
  .replace(/\]\(([^)\s]+)\)/g, (_match, target) => `](${absolute(target)})`)
  .replace(/^(\[[^\]]+\]:\s+)(\S+)/gm, (_match, label, target) => `${label}${absolute(target)}`)
  .replace(/\n{3,}/g, '\n\n')
  .trim()

mkdirSync(join(docs, 'guide'), { recursive: true })
writeFileSync(join(docs, 'guide', 'readme.md'), `${guide}\n`)
console.log(`${items.length} pages of reference and the guide written`)

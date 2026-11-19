# 🌐 Website Development Checklist

> A comprehensive checklist to guide you from concept to launch — and beyond.

---

## 1. 📋 Planning & Strategy

### Goals & Requirements
- [ ] Define the purpose and primary goals of the website
- [ ] Identify the target audience and user personas
- [ ] List required features and functionality
- [ ] Set a realistic timeline and milestones
- [ ] Establish budget constraints

### Research
- [ ] Analyze competitor websites
- [ ] Gather inspiration and design references
- [ ] Research relevant keywords and search intent
- [ ] Identify legal/compliance requirements (GDPR, WCAG, etc.)

### Technical Planning
- [ ] Choose technology stack (CMS, framework, language)
- [ ] Plan hosting and infrastructure
- [ ] Define domain name and SSL certificate strategy
- [ ] Plan backup and disaster recovery approach

---

## 2. 🎨 Design

### UX & Information Architecture
- [ ] Create a sitemap
- [ ] Define user flows and key journeys
- [ ] Sketch wireframes for all key pages
- [ ] Plan navigation structure

### Visual Design
- [ ] Define brand identity (logo, colors, typography)
- [ ] Build a design system / component library
- [ ] Design mobile-first responsive layouts
- [ ] Create high-fidelity mockups for key pages
- [ ] Gather stakeholder feedback and iterate

### Assets
- [ ] Prepare and optimize images and icons
- [ ] Source or create illustrations and graphics
- [ ] Define and export all design tokens (spacing, colors, fonts)

---

## 3. ✍️ Content

- [ ] Audit existing content (if redesign)
- [ ] Write copy for all pages
- [ ] Optimize content for target keywords
- [ ] Prepare blog posts or articles (if applicable)
- [ ] Source or create photos and videos
- [ ] Write alt text for all images
- [ ] Prepare privacy policy, terms of service, cookie policy
- [ ] Proofread and edit all content

---

## 4. 💻 Development

### Setup
- [ ] Initialize version control repository (Git)
- [ ] Set up project structure and boilerplate
- [ ] Configure development environment (linting, formatting)
- [ ] Set up CI/CD pipeline

### Frontend
- [ ] Implement semantic HTML structure
- [ ] Build responsive CSS layouts
- [ ] Develop all UI components
- [ ] Implement navigation and routing
- [ ] Add micro-animations and transitions
- [ ] Handle loading, empty, and error states
- [ ] Implement dark mode (if applicable)

### Backend / CMS
- [ ] Set up database schema
- [ ] Implement authentication and authorization (if needed)
- [ ] Build API endpoints
- [ ] Integrate CMS and content models
- [ ] Implement form handling and validation
- [ ] Set up email sending (contact forms, notifications)

### Integrations
- [ ] Integrate analytics (Google Analytics, Plausible, etc.)
- [ ] Set up cookie consent management
- [ ] Integrate third-party services (payment, chat, maps, etc.)
- [ ] Configure social sharing meta tags (Open Graph, Twitter Cards)

---

## 5. 🔍 SEO

- [ ] Implement proper `<title>` and `<meta description>` tags on all pages
- [ ] Set up heading hierarchy (`<h1>` → `<h2>` → `<h3>`)
- [ ] Use semantic HTML5 elements
- [ ] Create and submit `sitemap.xml`
- [ ] Configure `robots.txt`
- [ ] Implement canonical URLs
- [ ] Add structured data / Schema.org markup
- [ ] Optimize URL slugs (short, descriptive, keyword-rich)
- [ ] Set up 301 redirects for changed URLs
- [ ] Configure hreflang tags (if multilingual)
- [ ] Verify site in Google Search Console

---

## 6. ⚡ Performance

- [ ] Optimize and compress all images (WebP format preferred)
- [ ] Implement lazy loading for images and iframes
- [ ] Minify CSS, JavaScript, and HTML
- [ ] Enable Gzip / Brotli compression
- [ ] Configure browser caching headers
- [ ] Use a CDN for static assets
- [ ] Defer non-critical JavaScript
- [ ] Achieve Core Web Vitals targets:
  - [ ] LCP (Largest Contentful Paint) < 2.5s
  - [ ] INP (Interaction to Next Paint) < 200ms
  - [ ] CLS (Cumulative Layout Shift) < 0.1
- [ ] Test with Google PageSpeed Insights (score ≥ 90)
- [ ] Implement `<link rel="preconnect">` for critical third parties

---

## 7. ♿ Accessibility (a11y)

- [ ] Use sufficient color contrast ratios (WCAG AA minimum)
- [ ] Ensure all interactive elements are keyboard navigable
- [ ] Add ARIA labels and roles where necessary
- [ ] Provide visible focus styles
- [ ] Test with a screen reader (NVDA, VoiceOver)
- [ ] Ensure all forms have proper labels
- [ ] Avoid relying solely on color to convey information
- [ ] Test with browser accessibility audit tools

---

## 8. 🧪 Testing & QA

### Functional Testing
- [ ] Test all forms and submission flows
- [ ] Test all links (internal and external, no 404s)
- [ ] Test all interactive elements and JavaScript features
- [ ] Verify all integrations work end-to-end

### Cross-Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Safari (iOS)
- [ ] Chrome for Android

### Responsive Testing
- [ ] Mobile (320px – 480px)
- [ ] Tablet (768px – 1024px)
- [ ] Desktop (1280px+)
- [ ] Large screens (1440px+)

### Security
- [ ] Enable HTTPS and redirect HTTP → HTTPS
- [ ] Validate and sanitize all user inputs
- [ ] Protect against XSS and CSRF attacks
- [ ] Keep all dependencies up to date
- [ ] Remove debug/test code and console logs
- [ ] Set secure HTTP headers (CSP, HSTS, X-Frame-Options)

---

## 9. 🚀 Launch

- [ ] Finalize domain DNS settings
- [ ] Verify SSL certificate is valid
- [ ] Set up monitoring and uptime alerts
- [ ] Configure error tracking (Sentry, etc.)
- [ ] Create a rollback plan in case of issues
- [ ] Test the live site thoroughly after deploy
- [ ] Submit sitemap to Google Search Console
- [ ] Announce launch (email, social media)

---

## 10. 📈 Post-Launch

- [ ] Monitor analytics for unexpected drop-offs or errors
- [ ] Check Search Console for crawl errors or manual actions
- [ ] Gather user feedback
- [ ] Run a Lighthouse audit on production
- [ ] Plan first round of improvements
- [ ] Schedule regular content updates
- [ ] Set up automated backups
- [ ] Document infrastructure and deployment process

---

> **Tip:** Use the `/plan` command in Antigravity to break any of these phases into a detailed, executable plan with milestones.

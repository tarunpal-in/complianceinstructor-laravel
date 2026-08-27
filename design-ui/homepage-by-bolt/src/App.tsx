import { useState } from 'react';
import {
  ArrowRight,
  Award,
  BookOpen,
  CalendarDays,
  Check,
  ChevronDown,
  Clock3,
  Instagram,
  Linkedin,
  Menu,
  Monitor,
  Play,
  Search,
  ShieldCheck,
  ShoppingCart,
  Star,
  UserRound,
  UsersRound,
  X,
  Youtube,
} from 'lucide-react';

const seminarImage = 'https://images.pexels.com/photos/8761327/pexels-photo-8761327.jpeg?auto=compress&cs=tinysrgb&h=650&w=940';
const officeImage = 'https://images.pexels.com/photos/8761350/pexels-photo-8761350.jpeg?auto=compress&cs=tinysrgb&h=650&w=940';
const workshopImage = 'https://images.pexels.com/photos/8761559/pexels-photo-8761559.jpeg?auto=compress&cs=tinysrgb&h=650&w=940';
const laptopImage = 'https://images.pexels.com/photos/15543043/pexels-photo-15543043.jpeg?auto=compress&cs=tinysrgb&h=650&w=940';

const webinars = ['FMLA Compliance 2025: Avoiding Costly Mistakes 2026', 'FMLA Compliance 2025: Avoiding Costly Mistakes 2026', 'FMLA Compliance 2025: Avoiding Costly Mistakes 2026', 'FMLA Compliance 2025: Avoiding Costly Mistakes 2026'];
const plans = [
  { name: 'Regular', price: '$299', duration: '3 months', live: '3 Live + Recording', features: ['CEU Credits (SHRM & HRCI)', 'Certificate of Completion', 'PDF Study Materials'], muted: ['E-Transcript Access', 'Recording Download', 'Dedicated Support Specialist'] },
  { name: 'Plus', price: '$499', duration: '5 months', live: '5 Live Webinars', features: ['CEU Credits (SHRM & HRCI)', 'Certificate of Completion', 'PDF Study Materials', 'E-Transcript Access', 'Recording Download'], muted: ['Dedicated Support Specialist'] },
  { name: 'Standard', price: '$799', duration: '7 months', live: '7 Live Webinars', features: ['CEU Credits (All Providers)', 'Certificate of Completion', 'PDF Study Materials', 'E-Transcript Access', 'Recording Download Access', 'Priority Support Line'], muted: [] },
  { name: 'Business', price: '$999', duration: '12 months', live: '10 Live Webinars', features: ['CEU Credits (All Providers)', 'Certificate of Completion', 'PDF Study Materials', 'E-Transcript Access', 'Recording Download Access', 'Dedicated Account Manager'], muted: [], popular: true },
];

function App() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [email, setEmail] = useState('');
  const [notice, setNotice] = useState('');

  const showNotice = (message: string): void => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 2600);
  };

  return (
    <div className="site-shell">
      <div className="promo-bar">Purchase any webinar and use code <strong>TRAINING</strong> at checkout to save $10.</div>
      <header className="navbar">
        <a className="brand" href="#top" aria-label="Compliance Instructor home"><span className="brand-mark"><ShieldCheck size={23} /></span><span>COMPLIANCE<small>INSTRUCTOR</small></span></a>
        <nav className={mobileOpen ? 'nav-links open' : 'nav-links'}>
          {['Home', 'About', 'Training', 'Industry', 'Speakers', 'Memberships', 'Blog', 'Quiz', 'Help'].map((item: string) => <a key={item} href={`#${item.toLowerCase()}`} onClick={() => setMobileOpen(false)}>{item}{(item === 'Training' || item === 'Industry') && <ChevronDown size={13} />}</a>)}
        </nav>
        <div className="nav-actions">
          <button className="icon-button" aria-label="Search" onClick={() => setSearchOpen(!searchOpen)}>{searchOpen ? <X size={20} /> : <Search size={20} />}</button>
          <button className="icon-button" aria-label="Shopping cart" onClick={() => showNotice('Your cart is ready for checkout')}><ShoppingCart size={20} /></button>
          <button className="login-button" onClick={() => showNotice('Sign up is coming soon')}>Login / Sign Up</button>
          <button className="mobile-menu" aria-label="Toggle menu" onClick={() => setMobileOpen(!mobileOpen)}>{mobileOpen ? <X /> : <Menu />}</button>
        </div>
        {searchOpen && <div className="search-panel"><Search size={18} /><input autoFocus placeholder="Search webinars, courses, and topics" /></div>}
      </header>

      <main id="top">
        <section className="hero" id="home">
          <div className="hero-inner">
            <div className="hero-copy">
              <div className="eyebrow gold">• #1 COMPLIANCE TRAINING PLATFORM</div>
              <h1>Stay Ahead — <span>Learn from</span><br />the Best.</h1>
              <p className="hero-lede">Access live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM, and NASBA/CPE recertification credits — complete with study materials, certificates, and transcripts.</p>
              <div className="hero-buttons"><button className="button primary" onClick={() => showNotice('Welcome to your learning journey')}>Start Learning Now</button><a className="button secondary" href="#webinars">View Courses</a></div>
              <div className="stats"><div><strong>1,200+</strong><span>Webinars Delivered</span></div><div><strong>48,000+</strong><span>Professionals Trained</span></div><div><strong>4.9★</strong><span>Average Rating</span></div></div>
              <div className="recognized"><span>Recognized by:</span><div><b>HRCI</b><b>SHRM</b><b>CPE</b></div></div>
            </div>
            <div className="hero-visual">
              <div className="dot-grid hero-dots" /><div className="hero-image-wrap"><img src={seminarImage} alt="Professionals in a compliance seminar" /><div className="image-overlay" /></div>
              <div className="float-card certificate"><span className="mini-icon blue"><Award size={19} /></span><div><strong>Certificate Ready</strong><small>Instant download</small></div></div>
              <div className="float-card approved"><span className="mini-icon blue"><ShieldCheck size={19} /></span><div><strong>HRCI Approved</strong><small>Industry Accredited</small></div></div>
              <div className="float-card live"><span className="mini-icon green"><Star size={19} /></span><div><strong>Live Webinars Weekly</strong><small>Next: Aug 22, 2025</small></div></div>
              <div className="rating-card"><strong>4.9</strong><span>★★★★★</span><small>Rating</small></div>
            </div>
          </div>
        </section>

        <section className="process section" id="about"><div className="section-heading centered"><div className="eyebrow">SIMPLE PROCESS</div><h2>How It Works</h2><p>From registration to certification in three straightforward steps.</p></div><div className="process-grid">{[{icon: UserRound, title: 'Create Your Free Account', text: 'Sign up in under 60 seconds. No credit card required. Access your learner dashboard immediately.'}, {icon: Monitor, title: 'Explore Courses & Webinars', text: 'Browse hundreds of live and recorded compliance webinars across HR, payroll, manufacturing, and more.'}, {icon: Star, title: 'Enroll & Start Learning', text: 'Attend live or on-demand, earn accredited CEU credits, and receive your certificate of completion.'}].map(({ icon: Icon, title, text }, index: number) => <article className="process-card" key={title}><span className="step-number">0{index + 1}</span><div className="process-icon"><Icon size={23} /></div><h3>{title}</h3><p>{text}</p></article>)}</div></section>

        <section className="webinars section" id="training"><div className="section-heading split"><div><div className="eyebrow">LIVE EVENTS</div><h2>Upcoming Webinars</h2><p>Expert-led sessions with recognized CEU credits. Register today.</p></div><a className="text-link" href="#webinars">All Upcoming Webinars <ArrowRight size={16} /></a></div><div className="webinar-grid" id="webinars">{webinars.map((title: string, index: number) => <article className="webinar-card" key={`${title}-${index}`}><div className="webinar-photo"><img src={workshopImage} alt="Audience at a webinar" /><div className="date-badge"><CalendarDays size={14} /><strong>26</strong><span>AUG</span></div><span className="live-badge">● LIVE WEBINAR</span><div className="photo-tags"><b>HRCI</b><b>SHRM</b></div></div><div className="webinar-body"><h3>{title}</h3><p><CalendarDays size={18} /> Aug 22, 2025 · 10:00 AM PT / 1:00 PM ET</p><p><Clock3 size={18} /> 90 Min <em>◉ &nbsp;LIVE WEBINAR</em></p><div className="speaker"><div className="speaker-avatar">ST</div><div><b><UserRound size={15} /> Speaker</b><strong>Sandra Torres, SHRM–SCP</strong><small>HR Compliance Specialist</small></div></div><button onClick={() => showNotice('Webinar details opened')}>View Details</button></div></article>)}</div></section>

        <section className="categories section" id="industry"><div className="section-heading centered"><div className="eyebrow">BROWSE BY INDUSTRY</div><h2>Webinar Categories</h2><p>Built around the regulations you actually answer to</p></div><div className="category-grid">{[{name: 'Manufacturing', image: laptopImage}, {name: 'Human Resource', image: officeImage}, {name: 'Microsoft Office', image: seminarImage}].map((category: {name: string; image: string}) => <article className="category-card" key={category.name} style={{backgroundImage: `linear-gradient(0deg, rgba(5,39,72,.9), rgba(9,45,82,.05)), url(${category.image})`}}><div><small>48 Courses</small><h3>{category.name}</h3><button onClick={() => showNotice(`${category.name} courses opened`)}>View Courses</button></div></article>)}</div></section>

        <section className="benefits"><div className="benefit-grid">{[{icon: UsersRound, title: 'Expert-Led Online Sessions', text: 'Learn from certified practitioners with 15+ years of real-world compliance experience.'}, {icon: Monitor, title: 'Flexible Learning Options', text: 'Attend live webinars in real-time or watch recordings on your own schedule — 24/7 access.'}, {icon: Award, title: 'Career-Boosting Certifications', text: 'Earn HRCI, SHRM, and NASBA/CPE credits that count toward professional recertification.'}].map(({icon: Icon, title, text}) => <div className="benefit" key={title}><span><Icon size={26} /></span><div><h3>{title}</h3><p>{text}</p></div></div>)}</div></section>

        <section className="faculty section" id="speakers"><div className="faculty-copy"><div className="eyebrow">OUR INSTRUCTORS</div><h2>Experienced Faculty</h2><p>Our speakers have argued the cases, run the audits and built the programs. They bring the edge cases you won't find in a compliance handbook — and they stay on the line until your question is answered.</p><button className="button primary" onClick={() => showNotice('Meet the speakers')}>Meet the Speakers</button></div><div className="faculty-cards">{[seminarImage, officeImage, workshopImage, laptopImage].map((image: string, index: number) => <article className="faculty-card" key={image}><img src={image} alt="Compliance instructor" /><h3>{['Diane Whitfield', 'Diane Whitfield', 'Diane Whitfield', 'Diane Whitfield'][index]}</h3><p>SPHR · Former regional HR director, Fortune 100 retail</p><a href="#speakers">HRCI · SHRM</a></article>)}</div></section>

        <section className="accreditation section"><div className="section-heading centered"><div className="eyebrow">TRUSTED CREDENTIALS</div><h2>Accreditation Partners</h2><p>Every CourseMinistry webinar carries credits recognized by the industry's leading professional certification bodies.</p></div><div className="credential-grid"><div><strong>SHRM</strong><small>RECERTIFICATION<br />PROVIDER</small><span>Society for Human Resource Mgmt</span></div><div><strong>CPE</strong><small>NATIONAL REGISTRY OF<br />CPE SPONSORS</small><span>Continuing Professional Education</span></div><div><strong>HRCI</strong><small>RECERTIFICATION<br />PROVIDER</small><span>HR Certification Institute</span></div></div></section>

        <section className="stories section"><div className="section-heading split"><div><div className="eyebrow">WHAT PROFESSIONALS SAY</div><h2>Success Stories</h2></div><div className="review-pill"><span>★★★★★</span> <strong>4.8</strong> from 312 reviews</div></div><div className="story-grid">{['“Jason\'s H.O.P.E. framework gave me a concrete language to describe what great leadership looks like. I implemented the Ownership pillar the following week and saw an immediate shift in team accountability.”', '“One of the most practical webinars I have attended on Comply Masters. The Q&A was genuinely useful, the slides are clean and reference-able, and the CPE certificate was in my inbox within minutes.”', '“Jason\'s H.O.P.E. framework gave me a concrete language to describe what great leadership looks like. I implemented the Ownership pillar the following week and saw an immediate shift in team accountability.”'].map((quote: string, index: number) => <article className="story-card" key={quote + index}><span>★★★★★</span><p>{quote}</p><div className="story-person"><div>{['SM', 'MO', 'PS'][index]}</div><span><strong>{['Sandra Mitchell', 'Marcus Okafor', 'Priya Sharma'][index]}</strong><small>{['HR Director · BlueCross Health', 'VP of People Operations · FinServe Inc.', 'L&D Manager · GlobalTech Solutions'][index]}</small></span></div></article>)}</div></section>

        <section className="pricing section" id="memberships"><div className="section-heading centered"><div className="eyebrow">FLEXIBLE PRICING</div><h2>Membership Plans</h2><p>Invest in your professional development with a plan that matches your learning pace.</p></div><div className="plans-grid">{plans.map((plan) => <article className={plan.popular ? 'plan popular' : 'plan'} key={plan.name}>{plan.popular && <span className="popular-tag">MOST POPULAR</span>}<h3>{plan.name}</h3><div className="price">{plan.price} <small>/ {plan.duration}</small></div><b className="live-count">{plan.live}</b><hr />{plan.features.map((feature: string) => <p className="feature" key={feature}><Check size={16} />{feature}</p>)}{plan.muted.map((feature: string) => <p className="feature muted" key={feature}>⊗ {feature}</p>)}<button onClick={() => showNotice(`${plan.name} plan selected`)}>Get Started</button></article>)}</div><div className="callback"><div><strong>Not sure which plan is right for you?</strong><span>Our team can help match you with the best training package for your goals and budget.</span></div><button onClick={() => showNotice('We will be in touch soon')}>Request a Callback</button></div></section>
      </main>

      <footer className="footer"><div className="footer-grid"><div className="footer-intro"><a className="brand" href="#top"><span className="brand-mark"><ShieldCheck size={23} /></span><span>COMPLIANCE<small>INSTRUCTOR</small></span></a><p>Professional compliance training for HR, payroll, and operations teams across the United States.</p><strong>Stay informed — subscribe to our newsletter</strong><form onSubmit={(event) => { event.preventDefault(); showNotice(email ? 'You are subscribed' : 'Enter your email address'); setEmail(''); }}><input value={email} onChange={(event) => setEmail(event.target.value)} placeholder="your@email.com" aria-label="Email address" /><button>Subscribe</button></form><div className="socials"><Linkedin size={18} /><Instagram size={18} /><Youtube size={18} /></div></div><div className="footer-links">{[{title: 'Company', links: ['About Us', 'Speakers', 'Blog', 'Careers', 'Press']}, {title: 'Training', links: ['Live Webinars', 'Recorded Webinars', 'Packages', 'Memberships']}, {title: 'Resources', links: ['FAQ', 'Forum', 'Quiz', 'Help Center', 'Contact']}, {title: 'Legal', links: ['Terms of Service', 'Privacy Policy', 'Refund Policy', 'Cookie Policy']}].map((column) => <div key={column.title}><h4>{column.title}</h4>{column.links.map((link: string) => <a href="#top" key={link}>{link}</a>)}</div>)}</div></div><div className="payments"><strong>PAYMENTS OPTIONS</strong><div><b>VISA</b><b>AMEX</b><b>●●</b><b>PayPal</b></div></div><div className="copyright">© 2026 Compliance Instructor. All rights reserved.</div></footer>
      {notice && <div className="toast">{notice}</div>}
    </div>
  );
}

export default App;
